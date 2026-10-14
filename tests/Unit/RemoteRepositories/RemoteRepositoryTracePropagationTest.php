<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\RemoteRepositories;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Providers\TracingServiceProvider;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\RemoteRepositoryTestHelpers;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\TraceProbeJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The exact X-Amzn-Trace-Id a RemoteRepository call sends, for each kind of
 * work it can be made from. Every one is in Root= form: a bare id is not an
 * X-Ray header, and an API Gateway receiving one starts a new trace.
 */
class RemoteRepositoryTracePropagationTest extends TestCase
{
    use RemoteRepositoryTestHelpers;

    private const XRAY_ID = '/^1-[0-9a-f]{8}-[0-9a-f]{24}$/';

    private const ROOT = '1-67a92466-4b6aa15a05ffcd4c510de968';

    private const PARENT = '53995c3f42cd8ad8';

    private ?array $requestCallHeaders = null;

    protected function getPackageProviders($app)
    {
        return [TracingServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue.default', 'sync');
    }

    protected function defineRoutes($router)
    {
        $router->get('/trace/call', function () {
            $this->requestCallHeaders = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

            return response()->noContent();
        });

        $router->get('/trace/sync-job-call', function () {
            TraceProbeJob::$then = function () {
                $this->requestCallHeaders = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();
            };
            TraceProbeJob::dispatch();

            return response()->noContent();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteRepositoryConfig();
        TraceProbeJob::$then = null;
    }

    protected function tearDown(): void
    {
        TraceProbeJob::$then = null;
        parent::tearDown();
    }

    public function test_a_request_sends_its_root_parent_and_sampled()
    {
        $this->get('/trace/call', [
            TraceContext::HEADER => 'Self=1-67a92467-0123456789abcdef01234567;Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1',
        ])->assertNoContent();

        $this->assertSame('Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1', $this->requestCallHeaders[TraceContext::HEADER] ?? null);
        $this->assertArrayNotHasKey('X-Correlation-ID', $this->requestCallHeaders);
    }

    public function test_a_request_with_no_valid_root_sends_the_root_it_minted()
    {
        $this->get('/trace/call', [TraceContext::HEADER => self::ROOT])->assertNoContent();

        $sent = $this->requestCallHeaders[TraceContext::HEADER] ?? '';
        $this->assertMatchesRegularExpression('/^Root=1-[0-9a-f]{8}-[0-9a-f]{24}$/', $sent);
        $this->assertNotSame('Root='.self::ROOT, $sent);
        $this->assertSame('Root='.app(TraceContext::class)->current(), $sent);
    }

    public function test_a_sync_job_sends_its_request_root_parent_and_sampled()
    {
        // A sync job runs inside its request, so it is still on the request's
        // trace, Parent included.
        $this->get('/trace/sync-job-call', [
            TraceContext::HEADER => 'Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1',
        ])->assertNoContent();

        $this->assertSame('Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1', $this->requestCallHeaders[TraceContext::HEADER] ?? null);
    }

    public function test_a_job_dispatched_by_a_request_sends_its_root_and_sampled()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::ROOT, TraceContext::PAYLOAD_SAMPLED_KEY => '1']);

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertSame('Root='.self::ROOT.';Sampled=1', $headers[TraceContext::HEADER] ?? null);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
        // Local to each service, so not propagated.
        $this->assertArrayNotHasKey('X-Request-ID', $headers);
    }

    public function test_a_job_dispatched_by_a_command_sends_its_root()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::ROOT]);

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertSame('Root='.self::ROOT, $headers[TraceContext::HEADER] ?? null);
    }

    public function test_a_command_run_sends_its_minted_root()
    {
        $this->app['events']->dispatch(new CommandStarting('app:nightly-sync', new ArrayInput([]), new NullOutput));
        $runRoot = app(TraceContext::class)->current();

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertMatchesRegularExpression(self::XRAY_ID, $runRoot);
        $this->assertSame('Root='.$runRoot, $headers[TraceContext::HEADER] ?? null);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }

    public function test_header_forwarding_config_cannot_replace_or_duplicate_the_trace_header()
    {
        config([
            'app.remote_repository.passthrough_headers' => [TraceContext::HEADER],
            'app.remote_repository.contextual_headers' => ['x-amzn-trace-id' => null],
        ]);

        $this->get('/trace/call', [
            TraceContext::HEADER => 'Self=1-67a92467-0123456789abcdef01234567;Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1',
        ])->assertNoContent();

        $traceHeaders = array_filter(array_keys($this->requestCallHeaders), fn ($name) => strcasecmp($name, TraceContext::HEADER) === 0);
        $this->assertSame([TraceContext::HEADER], array_values($traceHeaders));
        $this->assertSame('Root='.self::ROOT.';Parent='.self::PARENT.';Sampled=1', $this->requestCallHeaders[TraceContext::HEADER]);
    }

    public function test_no_trace_header_when_no_trace_has_started()
    {
        // Even with the inbound header present and configured for forwarding.
        config(['app.remote_repository.passthrough_headers' => [TraceContext::HEADER]]);
        $request = Request::create('/api/test', 'GET');
        $request->headers->set(TraceContext::HEADER, 'Root='.self::ROOT);
        $this->app->instance('request', $request);

        $headers = $this->createTestRepositoryWithTokenTrigger()->triggerRequestHeaders();

        $this->assertArrayNotHasKey(TraceContext::HEADER, $headers);
        $this->assertArrayNotHasKey('X-Correlation-ID', $headers);
    }

    private function processJob(array $payload): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);

        $this->app['events']->dispatch(new JobProcessing('sqs', $job));
    }
}
