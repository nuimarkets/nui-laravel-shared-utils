# AttachmentService - Reusable File Upload Management

## Overview

The `AttachmentService` provides a standardized way to handle file uploads to S3 across all Laravel services. It handles unique filename generation, S3 uploads with error handling, and database record creation.

## Basic Usage

### 1. Configure S3 Disk

Add to `config/filesystems.php`:

```php
'product-attachment' => [
    'driver' => 's3',
    'region' => env('AWS_PRODUCT_ATTACHMENT_REGION'),
    'bucket' => env('AWS_PRODUCT_ATTACHMENT_BUCKET'),
    'key' => env('AWS_PRODUCT_ATTACHMENT_ACCESS_KEY_ID'),
    'secret' => env('AWS_PRODUCT_ATTACHMENT_SECRET_ACCESS_KEY'),
],
```

### 2. Create Attachment Model

```php
<?php

namespace App\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'tenant_uuid', 'file_name',
        'file_size', 'bucket_path', 'type', 'created_by'
    ];
}
```

### 3. Add Trait to Parent Entity

```php
use NuiMarkets\LaravelSharedUtils\Traits\HasAttachments;

class Product extends Model
{
    use HasAttachments;

    protected function getAttachmentModel(): string
    {
        return Attachment::class;
    }

    protected function getAttachmentPivotTable(): string
    {
        return 'product_attachments';
    }

    protected function getAttachmentForeignKey(): string
    {
        return 'product_id';
    }
}
```

### 4. Service Implementation

```php
<?php

namespace App\Services;

use App\Entities\Attachment;
use NuiMarkets\LaravelSharedUtils\Services\AttachmentService;

class ProductAttachmentService extends AttachmentService
{
    public function __construct()
    {
        parent::__construct(
            'product-attachment',      // S3 disk name
            Attachment::class,          // Attachment model
            'product_attachments',      // Pivot table
            'product_id'                // Foreign key
        );
    }
}
```

### 5. Controller Usage

```php
use NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits\ManagesAttachments;
use NuiMarkets\LaravelSharedUtils\Http\Requests\AttachmentUploadRequest;

class ProductController extends Controller
{
    use ManagesAttachments;

    protected $attachmentService;

    public function __construct(ProductAttachmentService $attachmentService)
    {
        $this->attachmentService = $attachmentService;
    }

    public function uploadAttachments(AttachmentUploadRequest $request, string $uuid)
    {
        $product = Product::where('uuid', $uuid)->firstOrFail();

        $attachments = $this->attachmentService->processAttachments(
            $product,
            $request->file('attachments'),
            $request->input('type')
        );

        return $this->uploadSuccessResponse($attachments, count($attachments));
    }

    public function listAttachments(Request $request, string $uuid)
    {
        $product = Product::where('uuid', $uuid)->firstOrFail();
        return $this->listAttachmentsResponse($product);
    }

    public function downloadAttachment(Request $request, string $uuid, string $attachmentUuid)
    {
        $product = Product::where('uuid', $uuid)->firstOrFail();
        $attachment = $product->attachments()->where('uuid', $attachmentUuid)->firstOrFail();

        return $this->downloadAttachmentResponse($attachment, 'product-attachment', $product);
    }

    public function deleteAttachment(Request $request, string $uuid, string $attachmentUuid)
    {
        $product = Product::where('uuid', $uuid)->firstOrFail();
        $attachment = $product->attachments()->where('uuid', $attachmentUuid)->firstOrFail();

        $this->attachmentService->deleteAttachment($attachment, $product);

        return $this->deleteAttachmentResponse();
    }
}
```

## Polymorphic Attachments (Alternative)

For services using polymorphic relationships (like connect-auth Media):

```php
class User extends Model
{
    use HasAttachments;

    protected function usesPolymorphicAttachments(): bool
    {
        return true; // Use polymorphic instead of pivot
    }

    protected function getAttachmentModel(): string
    {
        return Media::class;
    }
}
```

## Customization

### Custom Validation

```php
class AvatarUploadRequest extends AttachmentUploadRequest
{
    protected function getFileValidationRules(): array
    {
        return [
            'required',
            'file',
            'max:5120', // 5MB for avatars
            'mimes:jpg,jpeg,png,gif',
        ];
    }

    protected function getAllowedTypes(): array
    {
        return ['avatar', 'profile_image'];
    }
}
```

### Custom Service Logic

```php
class AvatarService extends AttachmentService
{
    public function updateUserAvatar(User $user, UploadedFile $file)
    {
        // Delete old avatar
        if ($user->avatar) {
            $this->deleteAttachment($user->avatar, $user);
        }

        // Upload new avatar
        $attachments = $this->processAttachments($user, $file, 'avatar');

        return $attachments[0];
    }
}
```

## Security Considerations

- **Tenant Isolation**: Always filter by tenant_uuid/tenant_id
- **File Validation**: Size and type validated both client and server-side
- **S3 IAM Roles**: Use IAM roles in production, keys only for local dev
- **Unique Filenames**: Prevents collisions and predictable URLs
- **Soft Deletes**: Attachments are soft deleted for audit trail
- **Authorization Checks**: ManagesAttachments trait includes tenant isolation validation

## Error Handling

The service logs all S3 operations and provides detailed error messages:

```json
{
  "aws_error_code": "AccessDenied",
  "aws_error_message": "Access Denied",
  "bucket": "my-bucket",
  "path": "tenant-123/attachments/file.jpg"
}
```

## Performance Tips

1. **Use IAM roles** in production (no key management)
2. **Enable CloudFront** for download performance
3. **Batch uploads** wisely (max 10 files per request)
4. **Client-side validation** for better UX
5. **Eager load attachments** when listing entities to avoid N+1 queries

## Database Migrations

### Pivot Table Approach (Recommended)

1. Run base migration to create `attachments` table
2. Copy `create_pivot_attachments_table.php.stub` and customize for your entity
3. Update table name and foreign keys

Example for products:
```php
Schema::create('product_attachments', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('product_id');
    $table->unsignedBigInteger('attachment_id');
    $table->unsignedBigInteger('added_by');
    $table->timestamps();

    $table->foreign('product_id')
        ->references('id')
        ->on('products')
        ->onDelete('cascade');

    $table->foreign('attachment_id')
        ->references('id')
        ->on('attachments')
        ->onDelete('cascade');

    $table->unique(['product_id', 'attachment_id']);
});
```

### Polymorphic Approach (For Multiple Entity Types)

Use when attachments can belong to multiple entity types (Users, Products, Orders, etc.):

1. Run base migration to create `attachments` table
2. Run polymorphic migration to add `attachable_type` and `attachable_id` columns

## API Examples

### Upload Attachments
```bash
POST /products/{uuid}/attachments
Content-Type: multipart/form-data

attachments[]: file1.jpg
attachments[]: file2.pdf
type: image
```

### List Attachments
```bash
GET /products/{uuid}/attachments

Response:
{
  "data": [
    {
      "uuid": "abc-123",
      "file_name": "product-image.jpg",
      "file_size": 102400,
      "type": "image",
      "path": "tenant-123/attachments/product-image_20251004_123456_abc12345.jpg",
      "created_at": "2025-10-04T12:34:56Z"
    }
  ],
  "meta": { "code": 200 }
}
```

### Download Attachment
```bash
GET /products/{uuid}/attachments/{attachment_uuid}/download

Response: Binary file stream with original filename
```

### Delete Attachment
```bash
DELETE /products/{uuid}/attachments/{attachment_uuid}

Response:
{
  "meta": {
    "message": "Attachment deleted successfully.",
    "code": 200
  }
}
```
