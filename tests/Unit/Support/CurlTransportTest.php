<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use NuiMarkets\LaravelSharedUtils\Support\CurlTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurlTransportTest extends TestCase
{
    /**
     * Guzzle 7.9.0 and 7.9.1 use cURL only from libcurl 7.34, and from 7.13 a
     * request asking for TLS 1.2 (Laravel's default) goes to streams unless
     * libcurl is 7.34 or later and built with SSL; other 7.x releases accept
     * older or SSL-less builds. The check takes the strictest rule, so it never
     * reports cURL where an installable Guzzle would use streams.
     */
    public static function platforms(): array
    {
        return [
            'current libcurl, both functions' => [true, true, true, '8.14.1', true, true],
            'at the floor' => [true, true, true, '7.34.0', true, true],
            'just under the floor' => [true, true, true, '7.33.9', true, false],
            'old Guzzle floor only' => [true, true, true, '7.21.2', true, false],
            'only curl_exec' => [true, true, false, '8.14.1', true, true],
            'only curl_multi_exec' => [true, false, true, '8.14.1', true, true],
            'both functions disabled' => [true, false, false, '8.14.1', true, false],
            'libcurl version unknown' => [true, true, true, null, true, false],
            'extension constants missing' => [false, true, true, '8.14.1', true, false],
            'libcurl without SSL' => [true, true, true, '8.14.1', false, false],
        ];
    }

    #[DataProvider('platforms')]
    public function test_reports_curl_only_where_every_installable_guzzle_uses_it(bool $extension, bool $exec, bool $multiExec, ?string $libcurl, bool $tls12, bool $expected)
    {
        $this->assertSame($expected, CurlTransport::usable($extension, $exec, $multiExec, $libcurl, $tls12));
    }

    public function test_this_process_has_curl()
    {
        $this->assertTrue(CurlTransport::available());
    }
}
