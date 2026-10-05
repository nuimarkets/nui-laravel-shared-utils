<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Handler\TestHandler;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\Providers\TracingServiceProvider;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\TraceProbeJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class TracingServiceProviderTest extends TestCase
{
    private const XRAY_ID = '/^1-[0-9a-f]{8}-[0-9a-f]{24}$/';

    private const REQUEST_ROOT = '1-67a92466-4b6aa15a05ffcd4c510de968';

    private const FULL_REQUEST_HEADER = 'Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1';

    protected function getPackageProviders($app)
    {
        return [TracingServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('logging.default', 'trace-test');
        $app['config']->set('logging.channels.trace-test', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        TraceProbeJob::$ranWith = null;
    }

    public function test_the_context_is_scoped_to_one_unit_of_work()
    {
        $first = app(TraceContext::class);
        $this->assertSame($first, app(TraceContext::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(TraceContext::class));
    }

    public function test_a_job_dispatched_during_a_request_carries_the_request_trace_id()
    {
        $this->setRequestTraceHeader(self::FULL_REQUEST_HEADER);

        $this->assertSame(self::REQUEST_ROOT, $this->payloadTraceId());
    }

    public function test_jobs_dispatched_with_no_inbound_trace_share_one_minted_id()
    {
        $first = $this->payloadTraceId();

        $this->assertMatchesRegularExpression(self::XRAY_ID, $first);
        $this->assertSame($first, $this->payloadTraceId());
    }

    public function test_a_dispatch_with_no_inbound_trace_puts_the_minted_id_in_the_log_context()
    {
        TraceProbeJob::dispatch();

        $minted = TraceProbeJob::$ranWith;
        $this->assertMatchesRegularExpression(self::XRAY_ID, $minted);
        $this->assertSame($minted, app(TraceContext::class)->current());
        $this->assertLoggedTraceId($minted);
    }

    public function test_a_real_payload_carries_its_id_to_the_worker()
    {
        $this->setRequestTraceHeader(self::FULL_REQUEST_HEADER);
        $payload = $this->createPayload();

        // The worker side: a fresh unit of work with no request header.
        $this->app->instance('request', Request::create('/'));
        $this->resetLikeTheWorker();
        $this->processJob($payload);

        $this->assertSame(self::REQUEST_ROOT, app(TraceContext::class)->current());
        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    public function test_a_command_called_from_inside_a_job_keeps_the_job_id()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);

        $this->startCommand('app:nested-call');

        $this->assertSame(self::REQUEST_ROOT, app(TraceContext::class)->current());
        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    public function test_a_worker_job_adopts_the_trace_id_in_its_payload()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);

        $this->assertSame(self::REQUEST_ROOT, app(TraceContext::class)->current());
        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    public function test_a_job_dispatched_by_a_job_carries_the_parent_trace_id()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);

        $this->assertSame(self::REQUEST_ROOT, $this->payloadTraceId());
    }

    public function test_a_job_arriving_without_a_trace_id_mints_one()
    {
        $this->processJob(['displayName' => 'ExternalMessage']);

        $minted = app(TraceContext::class)->current();
        $this->assertMatchesRegularExpression(self::XRAY_ID, $minted);
        $this->assertLoggedTraceId($minted);
    }

    public function test_a_job_with_an_unusable_trace_id_mints_one()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => ['not' => 'a string']]);

        $this->assertMatchesRegularExpression(self::XRAY_ID, app(TraceContext::class)->current());
    }

    public function test_back_to_back_jobs_in_one_worker_never_share_an_id()
    {
        $this->resetLikeTheWorker();
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);

        $this->resetLikeTheWorker();
        $this->processJob([]);

        $second = app(TraceContext::class)->current();
        $this->assertNotSame(self::REQUEST_ROOT, $second);
        $this->assertLoggedTraceId($second);
    }

    public function test_a_job_never_inherits_the_previous_job_id_even_without_the_worker_reset()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);
        $this->processJob([]);

        $second = app(TraceContext::class)->current();
        $this->assertNotSame(self::REQUEST_ROOT, $second);
        $this->assertLoggedTraceId($second);
    }

    public function test_a_job_logs_its_id_when_the_worker_clears_only_the_log_context()
    {
        // The package's WorkCommand builds its Worker with no resetScope: it
        // clears the log context after each job but never forgets scoped
        // instances, so the context still holds the previous job's id.
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);
        Log::withoutContext();

        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);

        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    public function test_a_command_run_mints_one_id_shared_by_its_logs_and_jobs()
    {
        $this->startCommand('app:nightly-sync');

        $runId = app(TraceContext::class)->current();
        $this->assertMatchesRegularExpression(self::XRAY_ID, $runId);
        $this->assertLoggedTraceId($runId);
        $this->assertSame($runId, $this->payloadTraceId());
        $this->assertSame($runId, $this->payloadTraceId());
    }

    public function test_a_command_called_from_another_command_keeps_the_caller_id()
    {
        $this->startCommand('app:nightly-sync');
        $runId = app(TraceContext::class)->current();

        $this->startCommand('app:nested-call');

        $this->assertSame($runId, app(TraceContext::class)->current());
        $this->assertLoggedTraceId($runId);
    }

    public function test_a_command_called_during_a_request_leaves_the_request_log_context_alone()
    {
        $this->setRequestTraceHeader(self::FULL_REQUEST_HEADER);
        // As RequestLoggingMiddleware sets it: the raw inbound header.
        Log::withContext([LogFields::TRACE_ID_HEADER => self::FULL_REQUEST_HEADER]);

        $this->startCommand('cache:clear');

        $this->assertSame(self::REQUEST_ROOT, app(TraceContext::class)->current());
        $this->assertSame(self::FULL_REQUEST_HEADER, $this->loggedContext()[LogFields::TRACE_ID_HEADER]);
    }

    public function test_a_sync_job_runs_under_its_request_id_and_leaves_the_request_log_context_alone()
    {
        $this->setRequestTraceHeader(self::FULL_REQUEST_HEADER);
        // As RequestLoggingMiddleware sets it: the raw inbound header.
        Log::withContext([LogFields::TRACE_ID_HEADER => self::FULL_REQUEST_HEADER]);

        TraceProbeJob::dispatch();

        $this->assertSame(self::REQUEST_ROOT, TraceProbeJob::$ranWith);
        $this->assertSame(self::FULL_REQUEST_HEADER, $this->loggedContext()[LogFields::TRACE_ID_HEADER]);
    }

    private function setRequestTraceHeader(string $header): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Amzn-Trace-Id', $header);
        $this->app->instance('request', $request);
    }

    /**
     * The payload a job dispatched right now would be queued with.
     */
    private function createPayload(): array
    {
        $queue = $this->app['queue']->connection('sync');

        return json_decode((new \ReflectionMethod($queue, 'createPayload'))->invoke($queue, new TraceProbeJob, 'default'), true);
    }

    /**
     * The trace id a job dispatched right now would carry in its payload.
     */
    private function payloadTraceId(): ?string
    {
        return $this->createPayload()[TraceContext::PAYLOAD_KEY] ?? null;
    }

    private function processJob(array $payload): void
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn($payload);

        $this->app['events']->dispatch(new JobProcessing('sqs', $job));
    }

    /**
     * What the queue worker's resetScope does before it reserves each job.
     */
    private function resetLikeTheWorker(): void
    {
        Log::withoutContext();
        $this->app->forgetScopedInstances();
    }

    private function startCommand(string $command): void
    {
        $this->app['events']->dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput));
    }

    private function loggedContext(): array
    {
        Log::info('probe');

        $records = Log::driver()->getLogger()->getHandlers()[0]->getRecords();

        return end($records)->context;
    }

    private function assertLoggedTraceId(string $expected): void
    {
        $context = $this->loggedContext();

        $this->assertSame($expected, $context[LogFields::TRACE_ID_HEADER] ?? null);
        $this->assertSame($expected, $context[LogFields::TRACE_ID] ?? null);
    }
}
