<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Controllers\Traits;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits\ManagesAttachments;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ManagesAttachmentsTest extends TestCase
{
    use ManagesAttachments;

    public function test_list_attachments_response_structure()
    {
        $entity = $this->createTestEntity();

        $response = $this->listAttachmentsResponse($entity);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('meta', $data);
        $this->assertEquals(200, $data['meta']['code']);
        $this->assertCount(2, $data['data']);
    }

    public function test_download_throws_not_found_for_missing_file()
    {
        Storage::fake('test-disk');

        $attachment = $this->createTestAttachment();
        $attachment->bucket_path = 'nonexistent/file.jpg';

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Attachment file not found in storage');

        $this->downloadAttachmentResponse($attachment, 'test-disk');
    }

    public function test_download_throws_not_found_for_null_path()
    {
        $attachment = $this->createTestAttachment();
        $attachment->bucket_path = null;

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Attachment file path not found');

        $this->downloadAttachmentResponse($attachment, 'test-disk');
    }

    public function test_authorize_attachment_access_validates_entity_relationship()
    {
        $entity = $this->createTestEntity();
        $otherEntityAttachment = (object) [
            'id' => 999,
            'uuid' => 'uuid-999',
        ];

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Attachment not found');

        $this->authorizeAttachmentAccess($entity, $otherEntityAttachment);
    }

    public function test_authorize_attachment_access_validates_tenant_isolation()
    {
        $user = new TestAuthUser(['tenant_uuid' => 'tenant-123']);
        $this->actingAs($user);

        $entity = $this->createTestEntityWithTenant('tenant-456');
        $attachment = (object) ['id' => 1];

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Unauthorized access to attachment');

        $this->authorizeAttachmentAccess($entity, $attachment);
    }

    public function test_upload_success_response_structure()
    {
        $attachments = [
            $this->createTestAttachment(1),
            $this->createTestAttachment(2),
        ];

        $response = $this->uploadSuccessResponse($attachments, 2);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('attachments', $data['data']);
        $this->assertCount(2, $data['data']['attachments']);
        $this->assertEquals(201, $data['meta']['code']);
        $this->assertStringContainsString('2 attachment(s) uploaded successfully', $data['data']['message']);
    }

    public function test_delete_attachment_response_structure()
    {
        $response = $this->deleteAttachmentResponse();

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('Attachment deleted successfully.', $data['meta']['message']);
        $this->assertEquals(200, $data['meta']['code']);
    }

    public function test_error_response_structure()
    {
        $response = $this->attachmentErrorResponse('Test error message', 400);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertEquals('Test error message', $data['meta']['message']);
        $this->assertEquals(400, $data['meta']['code']);
    }

    // Helper methods
    private function createTestEntity()
    {
        $entity = new class extends Model
        {
            public $attachments;

            public function __construct()
            {
                parent::__construct();
                $this->attachments = new Collection([
                    (object) [
                        'id' => 1,
                        'uuid' => 'uuid-1',
                        'file_name' => 'file1.jpg',
                        'file_size' => 1024,
                        'type' => 'image',
                        'bucket_path' => 'path/file1.jpg',
                        'created_at' => now(),
                    ],
                    (object) [
                        'id' => 2,
                        'uuid' => 'uuid-2',
                        'file_name' => 'file2.pdf',
                        'file_size' => 2048,
                        'type' => 'document',
                        'bucket_path' => 'path/file2.pdf',
                        'created_at' => now(),
                    ],
                ]);
            }
        };

        return $entity;
    }

    private function createTestEntityWithTenant(string $tenantId)
    {
        return new class($tenantId)
        {
            public $attachments;

            public $tenant_uuid;

            public function __construct(string $tenantId)
            {
                $this->tenant_uuid = $tenantId;
                $this->attachments = new Collection([
                    (object) ['id' => 1],
                ]);
            }
        };
    }

    private function createTestAttachment(int $id = 1)
    {
        return (object) [
            'id' => $id,
            'uuid' => "uuid-{$id}",
            'file_name' => "file{$id}.jpg",
            'file_size' => 1024,
            'type' => 'image',
            'bucket_path' => "path/file{$id}.jpg",
        ];
    }
}

class TestAuthUser implements \Illuminate\Contracts\Auth\Authenticatable
{
    public $tenant_uuid;

    public function __construct(array $attributes)
    {
        $this->tenant_uuid = $attributes['tenant_uuid'] ?? null;
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return 1;
    }

    public function getAuthPassword()
    {
        return '';
    }

    public function getRememberToken()
    {
        return '';
    }

    public function setRememberToken($value) {}

    public function getRememberTokenName()
    {
        return '';
    }
}
