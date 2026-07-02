<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\AttachmentServiceProvider;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class AttachmentServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AttachmentServiceProvider::class,
        ];
    }

    public function test_provider_merges_inert_malware_scan_defaults()
    {
        $this->assertFalse(config('attachments.malware_scan.enabled'));
        $this->assertFalse(config('attachments.malware_scan.fail_open'));
        $this->assertSame('yr', config('attachments.malware_scan.binary'));
        $this->assertNull(config('attachments.malware_scan.rules_path'));
        $this->assertFalse(config('attachments.malware_scan.compiled_rules'));
        $this->assertSame(10, config('attachments.malware_scan.timeout_seconds'));
    }

    public function test_provider_publishes_attachments_config()
    {
        $paths = ServiceProvider::pathsToPublish(AttachmentServiceProvider::class, 'attachments-config');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('config/attachments.php', array_key_first($paths));
    }
}
