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

    /**
     * @param  string  $diskName  - S3 disk name from filesystems.php
     * @param  string  $attachmentModel  - Fully qualified attachment model class
     * @param  string|null  $pivotTable  - Pivot table name (null for polymorphic)
     * @param  string|null  $foreignKey  - Foreign key name (null for polymorphic)
     */
    public function __construct(
        string $diskName,
        string $attachmentModel,
        ?string $pivotTable = null,
        ?string $foreignKey = null
    ) {
        $this->diskName = $diskName;
        $this->attachmentModel = $attachmentModel;
        $this->pivotTable = $pivotTable;
        $this->foreignKey = $foreignKey;
    }

    /**
     * Process multiple file uploads.
     *
     * @param  mixed  $parentEntity  - Entity to attach files to (Product, Order, User, etc)
     * @param  array|UploadedFile  $files
     * @param  string|null  $type  - Attachment type (image, document, etc)
     * @param  int|null  $userId  - User ID performing upload
     * @return array Array of created attachment models
     *
     * @throws \Exception
     */
    public function processAttachments(
        $parentEntity,
        $files,
        ?string $type = null,
        ?int $userId = null
    ): array {
        // Normalize to array
        if (! is_array($files)) {
            $files = [$files];
        }

        $attachments = [];
        DB::beginTransaction();

        try {
            foreach ($files as $file) {
                $attachment = $this->uploadAndCreateAttachment(
                    $file,
                    $parentEntity->tenant_uuid ?? $parentEntity->tenant_id ?? null,
                    $type,
                    $userId ?? auth()->id() ?? 0
                );

                // Attach to parent entity
                if ($this->pivotTable) {
                    // Standard pivot table
                    $parentEntity->attachments()->attach($attachment->id, [
                        'added_by' => $userId ?? auth()->id() ?? 0,
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
            throw $e;
        }
    }

    /**
     * Upload file to S3 and create attachment record.
     *
     * @throws \Exception
     */
    protected function uploadAndCreateAttachment(
        UploadedFile $file,
        ?string $tenantIdentifier,
        ?string $type,
        int $userId
    ) {
        // Generate unique filename
        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);
        $uniqueFilename = $this->generateUniqueFilename($baseFilename, $extension);

        // Tenant-scoped path
        $bucketPath = $tenantIdentifier
            ? "{$tenantIdentifier}/attachments/"
            : 'attachments/';
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

        // Create attachment record
        $attachmentModel = $this->attachmentModel;

        return $attachmentModel::create([
            'uuid' => Str::uuid()->toString(),
            'tenant_uuid' => $tenantIdentifier,
            'file_name' => $originalFilename,
            'file_size' => $file->getSize(),
            'bucket_path' => $fullFilePath,
            'type' => $finalType,
            'created_by' => $userId,
        ]);
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
     * Delete attachment from S3 and database.
     */
    public function deleteAttachment($attachment, $parentEntity): void
    {
        DB::beginTransaction();

        try {
            // Detach from parent
            if ($this->pivotTable) {
                $parentEntity->attachments()->detach($attachment->id);
            } else {
                // Polymorphic - just delete the attachment
                // (parent relationship will be null after delete)
            }

            // Delete from S3
            if ($attachment->bucket_path) {
                Storage::disk($this->diskName)->delete($attachment->bucket_path);
            }

            // Soft delete attachment record
            $attachment->delete();

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
