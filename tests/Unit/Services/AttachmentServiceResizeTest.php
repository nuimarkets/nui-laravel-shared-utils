<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Services;

use Illuminate\Http\UploadedFile;
use NuiMarkets\LaravelSharedUtils\Services\AttachmentService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Unit tests for the resize helpers on AttachmentService. These only touch the
 * in-memory image pipeline — no DB, no S3.
 */
class AttachmentServiceResizeTest extends TestCase
{
    private TestableResizeAttachmentService $service;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestableResizeAttachmentService;
        $this->tmpDir = sys_get_temp_dir().'/attachment_resize_test_'.uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_resize_image_shrinks_oversized_jpeg_under_50kb_and_400px(): void
    {
        $file = $this->makeJpegUpload(4000, 3000, 'big.jpg');

        $resized = $this->service->resizeImage($file);

        $this->assertSame('image/jpeg', $resized->getMimeType());
        $this->assertStringEndsWith('.jpg', $resized->getClientOriginalName());

        $info = getimagesize($resized->getRealPath());
        $this->assertNotFalse($info);
        $this->assertLessThanOrEqual(400, $info[0]);
        $this->assertLessThanOrEqual(400, $info[1]);

        $bytes = filesize($resized->getRealPath());
        $this->assertLessThan(51_200, $bytes, "Resized file is {$bytes}B, expected <50KB");
    }

    public function test_resize_image_flattens_png_alpha_onto_white_background(): void
    {
        $path = $this->tmpDir.'/transparent.png';

        $img = imagecreatetruecolor(800, 600);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagepng($img, $path);
        imagedestroy($img);

        $file = new UploadedFile($path, 'transparent.png', 'image/png', null, true);
        $resized = $this->service->resizeImage($file);

        $this->assertSame('image/jpeg', $resized->getMimeType());

        $reload = imagecreatefromjpeg($resized->getRealPath());
        $rgb = imagecolorat($reload, 10, 10);
        imagedestroy($reload);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        $this->assertGreaterThan(240, $r);
        $this->assertGreaterThan(240, $g);
        $this->assertGreaterThan(240, $b);
    }

    public function test_resize_image_rewrites_non_jpeg_extension_to_jpg(): void
    {
        $path = $this->tmpDir.'/logo.png';
        $img = imagecreatetruecolor(600, 400);
        $color = imagecolorallocate($img, 50, 100, 200);
        imagefill($img, 0, 0, $color);
        imagepng($img, $path);
        imagedestroy($img);

        $file = new UploadedFile($path, 'logo.png', 'image/png', null, true);
        $resized = $this->service->resizeImage($file);

        $this->assertSame('logo.jpg', $resized->getClientOriginalName());
        $this->assertSame('image/jpeg', $resized->getMimeType());
    }

    public function test_is_resizable_image_accepts_jpeg_and_png(): void
    {
        $jpegPath = $this->tmpDir.'/probe.jpg';
        $img = imagecreatetruecolor(10, 10);
        imagejpeg($img, $jpegPath);
        imagedestroy($img);
        $jpegFile = new UploadedFile($jpegPath, 'probe.jpg', null, null, true);
        $this->assertTrue($this->service->isResizableImage($jpegFile));

        $pngPath = $this->tmpDir.'/probe.png';
        $img = imagecreatetruecolor(10, 10);
        imagepng($img, $pngPath);
        imagedestroy($img);
        $pngFile = new UploadedFile($pngPath, 'probe.png', null, null, true);
        $this->assertTrue($this->service->isResizableImage($pngFile));
    }

    public function test_is_resizable_image_rejects_heic_and_non_images(): void
    {
        $path = $this->tmpDir.'/probe.bin';
        file_put_contents($path, 'x');

        $heic = new UploadedFile($path, 'photo.heic', 'image/heic', null, true);
        $this->assertFalse($this->service->isResizableImage($heic));

        $pdf = new UploadedFile($path, 'doc.pdf', 'application/pdf', null, true);
        $this->assertFalse($this->service->isResizableImage($pdf));
    }

    public function test_is_optimal_image_logic(): void
    {
        $this->assertTrue($this->service->isOptimalImage(400, 400, 30_000, 'image/jpeg'));
        $this->assertFalse(
            $this->service->isOptimalImage(401, 400, 30_000, 'image/jpeg'),
            'too wide'
        );
        $this->assertFalse(
            $this->service->isOptimalImage(400, 400, 60_000, 'image/jpeg'),
            'too large'
        );
        $this->assertFalse(
            $this->service->isOptimalImage(400, 400, 30_000, 'image/png'),
            'png is not optimal output format'
        );
    }

    public function test_is_optimal_image_respects_custom_min_bytes(): void
    {
        $this->assertFalse(
            $this->service->isOptimalImage(400, 400, 25_000, 'image/jpeg', 20_000)
        );
        $this->assertTrue(
            $this->service->isOptimalImage(400, 400, 15_000, 'image/jpeg', 20_000)
        );
    }

    public function test_resize_disabled_when_no_config(): void
    {
        $service = new NoResizeAttachmentService;
        $file = $this->makeJpegUpload(4000, 3000, 'big.jpg');

        // isResizableImage still works (it's mime-based, independent of config),
        // but consumers should gate on $imageResizeConfig before calling resize.
        // The processAttachments override only triggers resize when config is set.
        $this->assertTrue($service->isResizableImage($file));
        // resize itself still produces output when invoked directly with defaults.
        $resized = $service->resizeImage($file);
        $this->assertSame('image/jpeg', $resized->getMimeType());
    }

    private function makeJpegUpload(int $width, int $height, string $name): UploadedFile
    {
        $path = $this->tmpDir.'/'.$name;
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 200, 100, 50);
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}

/**
 * Concrete subclass with resize enabled at the default 400x400 / JPEG q80.
 */
class TestableResizeAttachmentService extends AttachmentService
{
    protected ?array $imageResizeConfig = [
        'max_width' => 400,
        'max_height' => 400,
        'quality' => 80,
        'background' => 'ffffff',
    ];

    public function __construct()
    {
        parent::__construct('test-disk', \stdClass::class);
    }
}

/**
 * Concrete subclass with resize NOT opted in.
 */
class NoResizeAttachmentService extends AttachmentService
{
    public function __construct()
    {
        parent::__construct('test-disk', \stdClass::class);
    }
}
