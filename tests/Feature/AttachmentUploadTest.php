<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use NuiMarkets\LaravelSharedUtils\Http\Requests\AttachmentUploadRequest;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class AttachmentUploadTest extends TestCase
{
    public function test_validates_required_attachments()
    {
        $request = new AttachmentUploadRequest;
        $validator = Validator::make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attachments', $validator->errors()->toArray());
    }

    public function test_validates_file_size_limit()
    {
        $request = new AttachmentUploadRequest;

        // Create 11MB file (exceeds 10MB limit)
        $file = UploadedFile::fake()->create('large.pdf', 11 * 1024);

        $validator = Validator::make([
            'attachments' => [$file],
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attachments.0', $validator->errors()->toArray());
    }

    public function test_validates_allowed_mime_types()
    {
        $request = new AttachmentUploadRequest;

        // Invalid mime type
        $file = UploadedFile::fake()->create('test.exe', 100);

        $validator = Validator::make([
            'attachments' => [$file],
        ], $request->rules());

        $this->assertTrue($validator->fails());
    }

    public function test_validates_max_file_count()
    {
        $request = new AttachmentUploadRequest;

        // Create 11 files (exceeds 10 file limit)
        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = UploadedFile::fake()->image("test{$i}.jpg");
        }

        $validator = Validator::make([
            'attachments' => $files,
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attachments', $validator->errors()->toArray());
    }

    public function test_validates_minimum_file_count()
    {
        $request = new AttachmentUploadRequest;

        $validator = Validator::make([
            'attachments' => [],
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('attachments', $validator->errors()->toArray());
    }

    public function test_accepts_valid_files()
    {
        $request = new AttachmentUploadRequest;

        $files = [
            UploadedFile::fake()->image('test1.jpg'),
            UploadedFile::fake()->create('test2.pdf', 100, 'application/pdf'),
        ];

        $validator = Validator::make([
            'attachments' => $files,
        ], $request->rules());

        $this->assertFalse($validator->fails());
    }

    public function test_validates_attachment_type_enum()
    {
        $request = new AttachmentUploadRequest;

        $file = UploadedFile::fake()->image('test.jpg');

        // Valid type
        $validator = Validator::make([
            'attachments' => [$file],
            'type' => 'image',
        ], $request->rules());
        $this->assertFalse($validator->fails());

        // Invalid type
        $validator = Validator::make([
            'attachments' => [$file],
            'type' => 'invalid_type',
        ], $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('type', $validator->errors()->toArray());
    }

    public function test_type_field_is_optional()
    {
        $request = new AttachmentUploadRequest;

        $file = UploadedFile::fake()->image('test.jpg');

        $validator = Validator::make([
            'attachments' => [$file],
        ], $request->rules());

        $this->assertFalse($validator->fails());
    }
}
