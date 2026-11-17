<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use NuiMarkets\LaravelSharedUtils\Support\CurlTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurlTransportTest extends TestCase
{
    /**
     * Guzzle 7.9.0 and 7.9.1 use cURL only from libcurl 7.34; other 7.x
     * releases accept older versions. The check takes the strictest floor, so
     * it never reports cURL where an installable Guzzle would use streams.
     */
    public static function platforms(): array
    {
        return [
            'current libcurl, both functions' => [true, true, true, '8.14.1', true],
            'at the floor' => [true, true, true, '7.34.0', true],
            'just under the floor' => [true, true, true, '7.33.9', false],
            'old Guzzle floor only' => [true, true, true, '7.21.2', false],
            'only curl_exec' => [true, true, false, '8.14.1', true],
            'only curl_multi_exec' => [true, false, true, '8.14.1', true],
            'both functions disabled' => [true, false, false, '8.14.1', false],
            'libcurl version unknown' => [true, true, true, null, false],
            'extension constants missing' => [false, true, true, '8.14.1', false],
        ];
    }

    #[DataProvider('platforms')]
    public function test_reports_curl_only_where_every_installable_guzzle_uses_it(bool $extension, bool $exec, bool $multiExec, ?string $libcurl, bool $expected)
    {
        $this->assertSame($expected, CurlTransport::usable($extension, $exec, $multiExec, $libcurl));
    }

    public function test_this_process_has_curl()
    {
        $this->assertTrue(CurlTransport::available());
    }
}
