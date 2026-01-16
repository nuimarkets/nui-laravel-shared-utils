<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

use Aws\S3\Exception\S3Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reusable attachment upload and management service.
 * Handles S3 uploads, filename generation, and database record creation.
 */
class AttachmentService
{
    protected string $diskName;

    protected string $attachmentModel;

    protected ?string $pivotTable;

    protected ?string $foreignKey;

    /** @var callable|null */
    protected $pathBuilder;

    /**
     * @param  string  $diskName  - S3 disk name from filesystems.php
     * @param  string  $attachmentModel  - Fully qualified attachment model class
     * @param  string|null  $pivotTable  - Pivot table name (null for polymorphic)
     * @param  string|null  $foreignKey  - Foreign key name (null for polymorphic)
     * @param  callable|null  $pathBuilder  - Custom path builder: fn(?string $tenantId): string
     */
    public function __construct(
        string $diskName,
        string $attachmentModel,
        ?string $pivotTable = null,
        ?string $foreignKey = null,
        ?callable $pathBuilder = null
    ) {
        $this->diskName = $diskName;
        $this->attachmentModel = $attachmentModel;
        $this->pivotTable = $pivotTable;
        $this->foreignKey = $foreignKey;
        $this->pathBuilder = $pathBuilder;
    }

    /**
     * Process multiple file uploads.
     *
     * @param  mixed  $parentEntity  - Entity to attach files to (Product, Order, User, etc)
     * @param  array|UploadedFile  $files
     * @param  string|null  $type  - Attachment type (image, document, etc)
     * @param  int|string|null  $userId  - User ID performing upload (string for JWT-based services)
     * @return array Array of created attachment models
     *
     * @throws \Exception
     */
    public function processAttachments(
        $parentEntity,
        $files,
        ?string $type = null,
        int|string|null $userId = null
    ): array {
        // Normalize to array
        if (! is_array($files)) {
            $files = [$files];
        }

        // Resolve effective user ID once
        $effectiveUserId = $userId ?? auth()->id() ?? 0;

        // Step 1: Upload all files to S3 first (outside transaction)
        $uploadedData = [];
        $uploadedPaths = []; // Track for cleanup on failure

        try {
            foreach ($files as $file) {
                $data = $this->uploadFileToS3(
                    $file,
                    $parentEntity->tenant_uuid ?? $parentEntity->tenant_id ?? null,
                    $type,
                    $effectiveUserId
                );
                $uploadedData[] = $data;
                $uploadedPaths[] = $data['bucket_path'];
            }
        } catch (\Exception $e) {
            // Cleanup any uploaded files on upload failure
            $this->cleanupS3Files($uploadedPaths);
            throw $e;
        }

        // Step 2: Create database records in transaction
        $attachments = [];
        DB::beginTransaction();

        try {
            foreach ($uploadedData as $data) {
                // Create attachment record
                $attachmentModel = $this->attachmentModel;
                $attachment = $attachmentModel::create($data);

                // Attach to parent entity
                if ($this->pivotTable) {
                    // Standard pivot table
                    $parentEntity->attachments()->attach($attachment->id, [
                        'added_by' => $effectiveUserId,
                    ]);
                } else {
                    // Polymorphic relationship
                    $attachment->attachable_type = get_class($parentEntity);
                    $attachment->attachable_id = $parentEntity->id;
                    $attachment->save();
                }

                $attachments[] = $attachment;
            }

            DB::commit();

            return $attachments;

        } catch (\Exception $e) {
            DB::rollBack();
            // Cleanup S3 files on database failure
            $this->cleanupS3Files($uploadedPaths);
            throw $e;
        }
    }

    /**
     * Upload file to S3 and return data for database record creation.
     *
     * @return array Attachment data for database insertion
     *
     * @throws \Exception
     */
    protected function uploadFileToS3(
        UploadedFile $file,
        ?string $tenantIdentifier,
        ?string $type,
        int|string $userId
    ): array {
        // Generate unique filename
        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $uniqueFilename = $this->generateUniqueFilename($baseFilename, $extension);

        // Build bucket path - use custom pathBuilder if provided, otherwise default
        $bucketPath = $this->pathBuilder
            ? ($this->pathBuilder)($tenantIdentifier)
            : ($tenantIdentifier ? "{$tenantIdentifier}/attachments/" : 'attachments/');
        $fullFilePath = $bucketPath.$uniqueFilename;

        Log::info('S3: Starting upload', [
            'disk' => $this->diskName,
            'bucket' => config("filesystems.disks.{$this->diskName}.bucket"),
            'path' => $fullFilePath,
            'size' => $file->getSize(),
        ]);

        // Upload to S3 with error handling
        try {
            $uploaded = Storage::disk($this->diskName)->putFileAs(
                $bucketPath,
                $file,
                $uniqueFilename
            );

            if (! $uploaded) {
                throw new \Exception('S3 upload failed: putFileAs returned false');
            }

            Log::info('S3: Upload successful', [
                'path' => $fullFilePath,
                'size' => $file->getSize(),
            ]);

        } catch (S3Exception $e) {
            Log::error('S3: Upload failed with AWS error', [
                'aws_error_code' => $e->getAwsErrorCode(),
                'aws_error_message' => $e->getAwsErrorMessage(),
                'status_code' => $e->getStatusCode(),
                'bucket' => config("filesystems.disks.{$this->diskName}.bucket"),
            ]);
            throw new \Exception("S3 upload failed: {$e->getAwsErrorMessage()}", 0, $e);
        }

        // Determine attachment type
        $finalType = $type ?? $this->detectFileType($file);

        // Return data for database record creation
        return [
            'uuid' => Str::uuid()->toString(),
            'tenant_uuid' => $tenantIdentifier,
            'file_name' => $originalFilename,
            'file_size' => $file->getSize(),
            'bucket_path' => $fullFilePath,
            'type' => $finalType,
            'created_by' => $userId,
        ];
    }

    /**
     * Cleanup S3 files on transaction failure.
     *
     * @param  array  $paths  S3 file paths to delete
     */
    protected function cleanupS3Files(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $deleted = Storage::disk($this->diskName)->delete($path);
                if ($deleted) {
                    Log::info('S3: Cleaned up orphaned file after transaction failure', [
                        'path' => $path,
                    ]);
                } else {
                    Log::error('S3: Failed to cleanup orphaned file (delete returned false)', [
                        'path' => $path,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('S3: Failed to cleanup orphaned file', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Generate unique filename to prevent collisions.
     */
    protected function generateUniqueFilename(string $baseFilename, string $extension): string
    {
        $timestamp = now()->format('Ymd_His');
        $random = substr(md5(uniqid()), 0, 8);
        $sanitized = Str::slug($baseFilename);

        return "{$sanitized}_{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Detect file type from MIME type.
     */
    protected function detectFileType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        if ($mime === 'application/pdf') {
            return 'document';
        }

        if (in_array($mime, [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])) {
            return 'document';
        }

        if (str_starts_with($mime, 'text/')) {
            return 'text';
        }

        return 'other';
    }

    /**
     * Delete attachment - detach from parent and optionally delete from S3.
     *
     * @param  mixed  $attachment  The attachment to delete
     * @param  mixed  $parentEntity  Parent entity to detach from
     * @param  bool  $deleteFromStorage  Whether to delete from S3 (default: true)
     * @param  bool  $deleteRecord  Whether to delete the DB record (default: true)
     */
    public function deleteAttachment(
        $attachment,
        $parentEntity,
        bool $deleteFromStorage = true,
        bool $deleteRecord = true
    ): void {
        // Capture path before potential deletion
        $bucketPath = $attachment->bucket_path;

        DB::beginTransaction();

        try {
            // Detach from parent
            if ($this->pivotTable) {
                $parentEntity->attachments()->detach($attachment->id);
            } else {
                // Polymorphic - clear relationship fields
                if (! $deleteRecord) {
                    // When not deleting record, explicitly clear the relationship
                    $attachment->attachable_type = null;
                    $attachment->attachable_id = null;
                    $attachment->save();
                }
            }

            // Delete attachment record if requested
            if ($deleteRecord) {
                $attachment->delete();
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        // Delete from S3 AFTER successful DB commit to prevent orphaned references
        if ($deleteFromStorage && $bucketPath) {
            $deleted = Storage::disk($this->diskName)->delete($bucketPath);
            if (! $deleted) {
                // Log error but don't throw - DB is already committed
                // This prevents orphaned DB records pointing to non-existent files
                Log::error('S3: Failed to delete file after DB commit', [
                    'path' => $bucketPath,
                    'attachment_id' => $attachment->id ?? null,
                ]);
            }
        }
    }
}
