<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;

class RemoteRepositoryTracingTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
    }

    public function test_does_not_include_request_id_or_trace_headers_when_not_available()
    {
        $repository = $this->createTestRepositoryWithTokenTrigger();

        $headers = $repository->triggerRequestHeaders();

        $this->assertArrayNotHasKey('X-Request-ID', $headers);
        $this->assertArrayNotHasKey(TraceContext::HEADER, $headers);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }

    public function test_extracts_request_id_from_request_headers_as_fallback()
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Request-ID', 'fallback-request-456');
        $this->app->instance('request', $request);

        $repository = $this->createTestRepositoryWithTokenTrigger();

        $headers = $repository->triggerRequestHeaders();

        $this->assertArrayHasKey('X-Request-ID', $headers);
        $this->assertEquals('fallback-request-456', $headers['X-Request-ID']);
    }

    public function test_propagates_the_xray_trace_header_the_context_was_started_from()
    {
        $this->startTraceContextFrom('Self=1-67a92467-0123456789abcdef01234567;Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1');

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        // Root, Parent and Sampled continue the caller's trace; the load
        // balancer's Self is not forwarded.
        $this->assertSame('Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1', $headers[TraceContext::HEADER] ?? null);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }

    public function test_a_malformed_inbound_trace_header_is_replaced_by_a_minted_root()
    {
        $this->startTraceContextFrom('MalformedTraceId');

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertMatchesRegularExpression('/^Root=1-[0-9a-f]{8}-[0-9a-f]{24}$/', $headers[TraceContext::HEADER] ?? '');
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }

    public function test_the_inbound_header_is_not_forwarded_without_the_trace_context()
    {
        // TracingServiceProvider binds the context; the package auto-discovers
        // it, so this is a service that opted out of discovery.
        $request = Request::create('/api/test', 'GET');
        $request->headers->set(TraceContext::HEADER, 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1');
        $this->app->instance('request', $request);

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertArrayNotHasKey(TraceContext::HEADER, $headers);
    }

    private function startTraceContextFrom(string $header): void
    {
        $context = new TraceContext;
        $context->startFromHeader($header);
        $this->app->instance(TraceContext::class, $context);
    }
}
