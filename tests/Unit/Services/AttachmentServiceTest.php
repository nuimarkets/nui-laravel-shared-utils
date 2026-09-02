<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NuiMarkets\LaravelSharedUtils\Services\AttachmentService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Traits\HasAttachments;

class AttachmentServiceTest extends TestCase
{
    /** Seller org UUID, the scope connect-order puts in the bucket prefix. */
    private const TENANT_UUID = '82d0069b-16d4-416e-be93-584d7f6593dc';

    /**
     * A purchase-order name of the length that overflowed the column in
     * production; its slug alone outruns what the bucket prefix leaves.
     */
    private const LONG_BASE_FILENAME = '3627 - NEW PURCHASE ORDER - CHILLED LAMB LEGS BONE-IN 4MT KG\'S FOR DELIVERY SEP-OCT 2026 - PLEASE DOUBLE CHECK THE DELIVERY ADDRESS PRIOR TO DEPARTURE FROM THE PLANT - DO NOT SEND FROZEN PRODUCT';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('test-disk');

        // Create attachments table for testing
        $this->app['db']->connection()->getSchemaBuilder()->create('attachments', function ($table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('tenant_uuid')->nullable();
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('bucket_path');
            $table->string('type', 50);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();
        });

        // Create test entities table
        $this->app['db']->connection()->getSchemaBuilder()->create('test_entities', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('tenant_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function test_generates_unique_filename()
    {
        $service = new AttachmentService(
            'test-disk',
            TestAttachment::class,
            'test_attachments',
            'entity_id'
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('generateUniqueFilename');
        $method->setAccessible(true);

        $filename1 = $method->invoke($service, 'test-file', 'jpg');
        $filename2 = $method->invoke($service, 'test-file', 'jpg');

        $this->assertNotEquals($filename1, $filename2);
        $this->assertStringContainsString('test-file', $filename1);
        $this->assertStringEndsWith('.jpg', $filename1);
    }

    public function test_generated_filename_fits_the_budget_it_is_given()
    {
        $service = new AttachmentService(
            'test-disk',
            TestAttachment::class,
            'test_attachments',
            'entity_id'
        );

        $method = (new \ReflectionClass($service))->getMethod('generateUniqueFilename');
        $method->setAccessible(true);

        // 255 minus "order-attachments/{uuid}/" is what connect-order leaves.
        $budget = AttachmentService::MAX_BUCKET_PATH_LENGTH - strlen('order-attachments/'.self::TENANT_UUID.'/');

        $first = $method->invoke($service, self::LONG_BASE_FILENAME, 'pdf', $budget);
        $second = $method->invoke($service, self::LONG_BASE_FILENAME, 'pdf', $budget);

        $this->assertLessThanOrEqual($budget, mb_strlen($first));
        $this->assertStringEndsWith('.pdf', $first);
        $this->assertStringStartsWith('3627-new-purchase-order-chilled-lamb', $first);
        $this->assertNotEquals($first, $second, 'truncation must not cost the uniqueness suffix');
        $this->assertMatchesRegularExpression('/_\d{8}_\d{6}_[0-9a-f]{8}\.pdf$/', $first);
    }

    public function test_generated_filename_does_not_end_the_stem_on_a_hyphen()
    {
        $service = new AttachmentService(
            'test-disk',
            TestAttachment::class,
            'test_attachments',
            'entity_id'
        );

        $method = (new \ReflectionClass($service))->getMethod('generateUniqueFilename');
        $method->setAccessible(true);

        // "aaaa-bbbb-cccc" cut at 10 lands on the separator.
        $filename = $method->invoke($service, 'aaaa bbbb cccc', 'pdf', 10 + strlen('_20260901_213003_90047571.pdf'));

        $this->assertStringStartsWith('aaaa-bbbb_', $filename);
    }

    public function test_generated_filename_stays_unbounded_without_a_budget()
    {
        $service = new AttachmentService(
            'test-disk',
            TestAttachment::class,
            'test_attachments',
            'entity_id'
        );

        $method = (new \ReflectionClass($service))->getMethod('generateUniqueFilename');
        $method->setAccessible(true);

        $filename = $method->invoke($service, self::LONG_BASE_FILENAME, 'pdf');

        $this->assertStringContainsString('do-not-send-frozen-product', $filename);

        // Unbudgeted, this name plus connect-order's prefix is what overflowed
        // the column in production.
        $this->assertGreaterThan(
            AttachmentService::MAX_BUCKET_PATH_LENGTH,
            mb_strlen('order-attachments/'.self::TENANT_UUID.'/'.$filename)
        );
    }

    public function test_long_filename_still_yields_a_storable_bucket_path()
    {
        Storage::fake('test-disk');

        $entity = new TestEntity(['id' => 1, 'tenant_uuid' => self::TENANT_UUID]);
        $entity->exists = true;
        $entity->save();

        $file = UploadedFile::fake()->create(self::LONG_BASE_FILENAME.'.pdf', 11, 'application/pdf');

        $service = new AttachmentService(
            diskName: 'test-disk',
            attachmentModel: TestAttachment::class,
            pivotTable: 'test_attachments',
            foreignKey: 'entity_id',
            pathBuilder: fn (?string $scope) => "order-attachments/{$scope}/"
        );

        $attachments = $service->processAttachments($entity, $file);

        $this->assertCount(1, $attachments);
        $this->assertLessThanOrEqual(
            AttachmentService::MAX_BUCKET_PATH_LENGTH,
            mb_strlen($attachments[0]->bucket_path)
        );
        $this->assertStringStartsWith('order-attachments/'.self::TENANT_UUID.'/', $attachments[0]->bucket_path);
        $this->assertStringEndsWith('.pdf', $attachments[0]->bucket_path);
        Storage::disk('test-disk')->assertExists($attachments[0]->bucket_path);
    }

    public function test_long_original_file_name_is_truncated_keeping_its_extension()
    {
        $service = new AttachmentService(
            'test-disk',
            TestAttachment::class,
            'test_attachments',
            'entity_id'
        );

        $method = (new \ReflectionClass($service))->getMethod('truncateFileName');
        $method->setAccessible(true);

        $long = str_repeat('a', 300).'.pdf';
        $truncated = $method->invoke($service, $long);

        $this->assertSame(AttachmentService::MAX_FILE_NAME_LENGTH, mb_strlen($truncated));
        $this->assertStringEndsWith('.pdf', $truncated);

        $short = 'invoice.pdf';
        $this->assertSame($short, $method->invoke($service, $short));
    }

    public function test_detects_file_type_from_mime()
    {
        $service = new AttachmentService(
            'test-disk',
            TestAttachment::class,
            'test_attachments',
            'entity_id'
        );

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('detectFileType');
        $method->setAccessible(true);

        $imageFile = UploadedFile::fake()->image('test.jpg');
        $this->assertEquals('image', $method->invoke($service, $imageFile));

        $pdfFile = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
        $this->assertEquals('document', $method->invoke($service, $pdfFile));
    }

    public function test_uploads_file_to_s3_with_correct_path()
    {
        Storage::fake('test-disk');

        $file = UploadedFile::fake()->image('test.jpg');
        $entity = new TestEntity(['id' => 1, 'tenant_uuid' => 'tenant-123']);
        $entity->exists = true;
        $entity->save();

        $service = new AttachmentService('test-disk', TestAttachment::class, 'test_attachments', 'entity_id');
        $attachments = $service->processAttachments($entity, $file);

        $this->assertCount(1, $attachments);
        Storage::disk('test-disk')->assertExists($attachments[0]->bucket_path);
        $this->assertStringStartsWith('tenant-123/attachments/', $attachments[0]->bucket_path);
    }

    public function test_processes_multiple_files_atomically()
    {
        Storage::fake('test-disk');

        $files = [
            UploadedFile::fake()->image('1.jpg'),
            UploadedFile::fake()->image('2.jpg'),
        ];

        $entity = new TestEntity(['id' => 1]);
        $entity->exists = true;
        $entity->save();

        $service = new AttachmentService('test-disk', TestAttachment::class, 'test_attachments', 'entity_id');

        $attachments = $service->processAttachments($entity, $files);

        $this->assertCount(2, $attachments);

        // Verify both files uploaded
        foreach ($attachments as $attachment) {
            Storage::disk('test-disk')->assertExists($attachment->bucket_path);
        }
    }

    public function test_tenant_scoped_paths()
    {
        Storage::fake('test-disk');

        $entity = new TestEntity(['id' => 1, 'tenant_uuid' => 'tenant-456']);
        $entity->exists = true;
        $entity->save();

        $file = UploadedFile::fake()->image('test.jpg');

        $service = new AttachmentService('test-disk', TestAttachment::class, 'test_attachments', 'entity_id');
        $attachments = $service->processAttachments($entity, $file);

        $this->assertStringStartsWith('tenant-456/attachments/', $attachments[0]->bucket_path);
    }

    public function test_delete_attachment_removes_from_s3_and_database()
    {
        Storage::fake('test-disk');

        $entity = new TestEntity(['id' => 1]);
        $entity->exists = true;
        $entity->save();

        $file = UploadedFile::fake()->image('test.jpg');

        $service = new AttachmentService('test-disk', TestAttachment::class, 'test_attachments', 'entity_id');
        $attachments = $service->processAttachments($entity, $file);
        $attachment = $attachments[0];

        // Verify file exists
        Storage::disk('test-disk')->assertExists($attachment->bucket_path);

        // Delete attachment
        $bucketPath = $attachment->bucket_path;
        $service->deleteAttachment($attachment, $entity);

        // Verify file removed from S3
        Storage::disk('test-disk')->assertMissing($bucketPath);

        // Verify soft deleted in database
        $this->assertNotNull($attachment->fresh()->deleted_at);
    }
}

// Test stub classes
class TestAttachment extends Model
{
    use SoftDeletes;

    protected $table = 'attachments';

    protected $fillable = [
        'uuid', 'tenant_uuid', 'file_name',
        'file_size', 'bucket_path', 'type', 'created_by',
    ];
}

class TestEntity extends Model
{
    use HasAttachments;

    protected $table = 'test_entities';

    protected $fillable = ['id', 'name', 'tenant_uuid'];

    protected function getAttachmentModel(): string
    {
        return TestAttachment::class;
    }

    protected function getAttachmentPivotTable(): string
    {
        return 'test_attachments';
    }

    protected function getAttachmentForeignKey(): string
    {
        return 'entity_id';
    }

    // Mock relationship for testing
    public function attachments()
    {
        return new class
        {
            public function attach($id, $data) {}

            public function detach($id) {}
        };
    }
}
