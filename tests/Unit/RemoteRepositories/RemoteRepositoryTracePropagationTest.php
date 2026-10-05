<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Providers\TracingServiceProvider;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Outbound trace headers from work with no inbound request: a queued job or a
 * console command, once TracingServiceProvider is registered.
 */
class RemoteRepositoryTracePropagationTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    private const JOB_TRACE_ID = '1-5f84c7a1-0123456789abcdef01234567';

    protected function getPackageProviders($app)
    {
        return [TracingServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
    }

    public function test_a_job_sends_the_trace_id_it_carries()
    {
        app(TraceContext::class)->set(self::JOB_TRACE_ID);

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertSame(self::JOB_TRACE_ID, $headers['X-Amzn-Trace-Id'] ?? null);
        $this->assertSame(self::JOB_TRACE_ID, $headers['X-Correlation-ID'] ?? null);
        // Local to each service, so not propagated.
        $this->assertArrayNotHasKey('X-Request-ID', $headers);
    }

    public function test_a_command_run_sends_its_minted_trace_id()
    {
        $this->app['events']->dispatch(new CommandStarting('app:nightly-sync', new ArrayInput([]), new NullOutput));
        $runId = app(TraceContext::class)->current();

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertSame($runId, $headers['X-Amzn-Trace-Id'] ?? null);
        $this->assertSame($runId, $headers['X-Correlation-ID'] ?? null);
    }

    public function test_the_inbound_request_header_wins_over_a_set_trace_id()
    {
        $fullTraceHeader = 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1';
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', $fullTraceHeader);
        $this->app->instance('request', $request);
        app(TraceContext::class)->set(self::JOB_TRACE_ID);

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertSame($fullTraceHeader, $headers['X-Amzn-Trace-Id'] ?? null);
        $this->assertSame('1-67a92466-4b6aa15a05ffcd4c510de968', $headers['X-Correlation-ID'] ?? null);
    }

    public function test_no_trace_headers_when_nothing_has_set_or_minted_an_id()
    {
        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertArrayNotHasKey('X-Amzn-Trace-Id', $headers);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }
}
