<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Support;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class TraceContextTest extends TestCase
{
    private const XRAY_ID = '/^1-([0-9a-f]{8})-[0-9a-f]{24}$/';

    public function test_mint_produces_an_xray_trace_id_stamped_with_the_current_time()
    {
        $before = time();
        $traceId = TraceContext::mint();
        $after = time();

        $this->assertMatchesRegularExpression(self::XRAY_ID, $traceId);

        preg_match(self::XRAY_ID, $traceId, $matches);
        $this->assertGreaterThanOrEqual($before, hexdec($matches[1]));
        $this->assertLessThanOrEqual($after, hexdec($matches[1]));
    }

    public function test_mint_never_repeats()
    {
        $this->assertNotSame(TraceContext::mint(), TraceContext::mint());
    }

    public function test_current_is_null_with_no_request_header_and_nothing_set()
    {
        $this->assertNull((new TraceContext)->current());
    }

    public function test_current_reads_the_root_of_a_full_request_trace_header()
    {
        $this->setRequestTraceHeader('Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1');

        $this->assertSame('1-67a92466-4b6aa15a05ffcd4c510de968', (new TraceContext)->current());
    }

    public function test_current_reads_a_bare_request_trace_header_as_is()
    {
        // The gateway forwards the bare id, with no Root= segment.
        $this->setRequestTraceHeader('1-5f84c7a1-0123456789abcdef01234567');

        $this->assertSame('1-5f84c7a1-0123456789abcdef01234567', (new TraceContext)->current());
    }

    public function test_a_set_id_wins_over_the_request_header()
    {
        $this->setRequestTraceHeader('1-5f84c7a1-0123456789abcdef01234567');
        $context = new TraceContext;

        $context->set('1-5f84c7a1-fedcba9876543210fedcba98');

        $this->assertSame('1-5f84c7a1-fedcba9876543210fedcba98', $context->current());
    }

    public function test_ensure_returns_the_request_id_without_minting()
    {
        $this->setRequestTraceHeader('1-5f84c7a1-0123456789abcdef01234567');

        $this->assertSame('1-5f84c7a1-0123456789abcdef01234567', (new TraceContext)->ensure());
    }

    public function test_ensure_mints_once_and_keeps_the_id()
    {
        $context = new TraceContext;

        $first = $context->ensure();

        $this->assertMatchesRegularExpression(self::XRAY_ID, $first);
        $this->assertSame($first, $context->ensure());
        $this->assertSame($first, $context->current());
    }

    private function setRequestTraceHeader(string $header): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', $header);
        $this->app->instance('request', $request);
    }
}
