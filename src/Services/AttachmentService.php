<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

use Aws\S3\Exception\S3Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use NuiMarkets\LaravelSharedUtils\Exceptions\ResizeFailedException;
use Throwable;

/**
 * Reusable attachment upload and management service.
 * Handles S3 uploads, filename generation, and database record creation.
 */
class AttachmentService
{
    /**
     * Mimes accepted by resizeImage(). Animated GIF/WebP are flattened to a
     * static first frame because the driver is configured with
     * decodeAnimation: false — intentional for avatars/logos where the
     * bandwidth win outweighs losing animation.
     */
    private const RESIZABLE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * Pixel cap for resize input. GD truecolor decode allocates ~4 bytes/pixel,
     * so 15 MP ≈ 60 MB peak — fits comfortably under a 128 MB PHP CLI
     * memory_limit and covers typical phone-camera photos (iPhone 12 MP @
     * 4032×3024 = 12.2 MP; CON-1412 worst case 4032×3313 = 13.3 MP). Inputs
     * above the cap throw ResizeFailedException (HTTP 422). Raise only if
     * upload sizes legitimately need to grow and memory_limit is raised in
     * tandem.
     */
    private const PIXEL_CAP = 15_000_000;

    protected string $diskName;

    protected string $attachmentModel;

    protected ?string $pivotTable;

    protected ?string $foreignKey;

    /** @var callable|null */
    protected $pathBuilder;

    /** @var callable|null */
    protected $scopeResolver;

    /**
     * Per-consumer resize config. Null disables resizing (default).
     * Subclasses opt in by setting this property:
     *
     *   protected ?array $imageResizeConfig = [
     *       'max_width' => 400, 'max_height' => 400,
     *       'quality' => 80, 'background' => 'ffffff',
     *   ];
     *
     * Keys (all optional, with defaults shown):
     *   - max_width  (int, 400)        target longest-edge width
     *   - max_height (int, 400)        target longest-edge height
     *   - quality    (int, 80)         JPEG quality 0-100
     *   - background (string, ffffff)  hex colour used to flatten transparency
     */
    protected ?array $imageResizeConfig = null;

    private ?ImageManager $imageManager = null;

    /** @var list<string> */
    private array $tmpFiles = [];

    /**
     * @param  string  $diskName  - S3 disk name from filesystems.php
     * @param  string  $attachmentModel  - Fully qualified attachment model class
     * @param  string|null  $pivotTable  - Pivot table name (null for polymorphic)
     * @param  string|null  $foreignKey  - Foreign key name (null for polymorphic)
     * @param  callable|null  $pathBuilder  - Custom path builder: fn(?string $scope): string
     * @param  callable|null  $scopeResolver  - Extract scope identifier from parent entity: fn($entity): ?string
     */
    public function __construct(
        string $diskName,
        string $attachmentModel,
        ?string $pivotTable = null,
        ?string $foreignKey = null,
        ?callable $pathBuilder = null,
        ?callable $scopeResolver = null
    ) {
        $this->diskName = $diskName;
        $this->attachmentModel = $attachmentModel;
        $this->pivotTable = $pivotTable;
        $this->foreignKey = $foreignKey;
        $this->pathBuilder = $pathBuilder;
        $this->scopeResolver = $scopeResolver;
    }

    public function __destruct()
    {
        $this->cleanupTmpFiles();
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

        // Resize images first when the subclass has opted in. Non-image uploads
        // and resize-disabled subclasses fall through untouched.
        if ($this->imageResizeConfig !== null) {
            $resized = [];
            foreach ($files as $file) {
                $resized[] = $file instanceof UploadedFile && $this->isResizableImage($file)
                    ? $this->resizeImage($file)
                    : $file;
            }
            $files = $resized;
        }

        // Resolve effective user ID once
        $effectiveUserId = $userId ?? auth()->id() ?? 0;

        // Step 1: Upload all files to S3 first (outside transaction)
        $uploadedData = [];
        $uploadedPaths = []; // Track for cleanup on failure

        try {
            foreach ($files as $file) {
                $scope = $this->scopeResolver
                    ? ($this->scopeResolver)($parentEntity)
                    : ($parentEntity->tenant_uuid ?? $parentEntity->tenant_id ?? null);

                $data = $this->uploadFileToS3(
                    $file,
                    $scope,
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
                $uniqueFilename,
                [
                    'ContentDisposition' => $this->buildContentDisposition($originalFilename),
                    'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
                ]
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
     * Build RFC 6266/5987 Content-Disposition header with sanitized filename.
     */
    protected function buildContentDisposition(string $filename): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        $quoted = addcslashes($clean, '"\\/');
        $encoded = rawurlencode($clean);

        return "attachment; filename=\"{$quoted}\"; filename*=UTF-8''{$encoded}";
    }

    /**
     * Detect file type from MIME type.
     */
    protected function detectFileType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();

        // Guard against null MIME type (can occur with unrecognized files)
        if ($mime === null || $mime === '') {
            return 'other';
        }

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

    // ========================================================================
    // Image resize pipeline — opt-in via $imageResizeConfig.
    // ========================================================================

    /**
     * True when the uploaded file is a raster image we can decode and re-encode.
     */
    public function isResizableImage(UploadedFile $file): bool
    {
        $mime = strtolower((string) $file->getMimeType());

        return in_array($mime, self::RESIZABLE_MIMES, true);
    }

    /**
     * True when an existing image is already small enough to skip on backfill.
     */
    public function isOptimalImage(int $width, int $height, int $bytes, string $mime, int $minBytes = 51_200): bool
    {
        $cfg = $this->resolveResizeConfig();

        return strtolower($mime) === 'image/jpeg'
            && $width <= $cfg['max_width']
            && $height <= $cfg['max_height']
            && $bytes < $minBytes;
    }

    /**
     * Resize an image to the configured target (JPEG, flattened on background).
     * Returns a new UploadedFile pointing at a tmp file. Tmp file is cleaned up
     * when this service is destroyed, or eagerly via cleanupTmpFiles().
     *
     * @throws ResizeFailedException
     */
    public function resizeImage(UploadedFile $file): UploadedFile
    {
        $cfg = $this->resolveResizeConfig();

        $sourcePath = $file->getRealPath();
        if ($sourcePath === false || ! is_file($sourcePath)) {
            throw new ResizeFailedException('Source file not readable');
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new ResizeFailedException('Could not read image dimensions');
        }
        [$srcWidth, $srcHeight] = $info;

        if (($srcWidth * $srcHeight) > self::PIXEL_CAP) {
            throw new ResizeFailedException(sprintf(
                'Image exceeds pixel cap (%d > %d)',
                $srcWidth * $srcHeight,
                self::PIXEL_CAP
            ));
        }

        try {
            $image = $this->imageManager($cfg['background'])->decodePath($sourcePath);

            // scaleDown fits within both bounds, preserves aspect ratio, never upscales.
            // Prefer over cover()/coverDown() (which crop) and contain()/containDown()
            // (which pad to a fixed canvas) — see https://image.intervention.io/v4/modifying-images/resizing
            $image->scaleDown($cfg['max_width'], $cfg['max_height']);

            $encoded = $image->encodeUsingFileExtension('jpg', quality: $cfg['quality']);
        } catch (Throwable $e) {
            throw new ResizeFailedException('Resize failed: '.$e->getMessage(), $e);
        }

        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $tmpBase = tempnam(sys_get_temp_dir(), 'resized_');
        if ($tmpBase === false) {
            throw new ResizeFailedException('Failed to allocate resized tmp file');
        }
        $tmpPath = $tmpBase.'.jpg';
        if (! @rename($tmpBase, $tmpPath)) {
            @unlink($tmpBase);
            throw new ResizeFailedException('Failed to finalise resized tmp file path');
        }
        if (file_put_contents($tmpPath, (string) $encoded) === false) {
            @unlink($tmpPath);
            throw new ResizeFailedException('Failed to write resized tmp file');
        }
        $this->tmpFiles[] = $tmpPath;

        return new UploadedFile(
            $tmpPath,
            $baseName.'.jpg',
            'image/jpeg',
            null,
            true
        );
    }

    /**
     * Eagerly delete tmp files produced by resizeImage(). Long-running callers
     * (e.g. backfill artisan commands) should invoke this between iterations
     * so /tmp doesn't accumulate one resized blob per processed attachment.
     */
    public function cleanupTmpFiles(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tmpFiles = [];
    }

    /**
     * Merge subclass $imageResizeConfig with defaults. Throws if resize is
     * disabled — callers should gate on $imageResizeConfig !== null when that
     * matters (e.g. inside processAttachments).
     *
     * @return array{max_width:int,max_height:int,quality:int,background:string}
     */
    private function resolveResizeConfig(): array
    {
        $cfg = $this->imageResizeConfig ?? [];

        return [
            'max_width' => $cfg['max_width'] ?? 400,
            'max_height' => $cfg['max_height'] ?? 400,
            'quality' => $cfg['quality'] ?? 80,
            'background' => $cfg['background'] ?? 'ffffff',
        ];
    }

    /**
     * Lazily build (and cache) the ImageManager so multiple resize calls reuse it.
     *
     * Driver config: auto-orientation on decode, single-frame decode for animated
     * images, configurable background colour used to flatten transparency on JPEG
     * encode, and EXIF stripping.
     */
    private function imageManager(string $backgroundColor): ImageManager
    {
        if ($this->imageManager === null) {
            $this->imageManager = new ImageManager(
                new GdDriver,
                autoOrientation: true,
                decodeAnimation: false,
                backgroundColor: $backgroundColor,
                strip: true,
            );
        }

        return $this->imageManager;
    }
}
