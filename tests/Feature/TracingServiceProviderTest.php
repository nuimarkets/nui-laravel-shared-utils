<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Handler\TestHandler;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\StartTraceContext;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\Providers\TracingServiceProvider;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\TraceContextReadingMiddleware;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\TraceProbeJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class TracingServiceProviderTest extends TestCase
{
    private const XRAY_ID = '/^1-[0-9a-f]{8}-[0-9a-f]{24}$/';

    private const REQUEST_ROOT = '1-67a92466-4b6aa15a05ffcd4c510de968';

    private const REQUEST_PARENT = '53995c3f42cd8ad8';

    /**
     * What a load balancer hands the service: its own Self ahead of the
     * gateway's Root, Parent and Sampled.
     */
    private const INBOUND_HEADER = 'Self=1-67a92467-0123456789abcdef01234567;Root=1-67a92466-4b6aa15a05ffcd4c510de968;Parent=53995c3f42cd8ad8;Sampled=1';

    /**
     * What the route under test saw, recorded inside the request.
     */
    private array $seen = [];

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

        // A service lists its own logging middleware in its kernel. Resolving
        // the kernel here, before any provider boots, is also the order a
        // served request runs in (public/index.php builds the kernel and its
        // handle() boots the providers), so a provider that only hooks the
        // kernel's later resolution never reaches it.
        $app->make(HttpKernel::class)->pushMiddleware(TraceContextReadingMiddleware::class);
    }

    protected function defineRoutes($router)
    {
        $router->get('/trace/probe', function () {
            $context = app(TraceContext::class);
            $this->seen = [
                'root' => $context->current(),
                'parent' => $context->parent(),
                'sampled' => $context->sampled(),
                'payload' => $this->createPayload(),
                'second_payload' => $this->createPayload(),
                'log' => $this->loggedContext(),
            ];

            return response()->noContent();
        });

        $router->get('/trace/sync-job', function () {
            // As RequestLoggingMiddleware sets it: the raw inbound header.
            Log::withContext([LogFields::TRACE_ID_HEADER => self::INBOUND_HEADER]);

            TraceProbeJob::dispatch();

            $this->seen = [
                'header' => app(TraceContext::class)->header(),
                'log' => $this->loggedContext(),
            ];

            return response()->noContent();
        });

        $router->get('/trace/command', function () {
            Log::withContext([LogFields::TRACE_ID_HEADER => self::INBOUND_HEADER]);

            $this->startCommand('cache:clear');

            $this->seen = [
                'header' => app(TraceContext::class)->header(),
                'log' => $this->loggedContext(),
            ];

            return response()->noContent();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        TraceProbeJob::$ranWith = null;
        TraceProbeJob::$ranWithHeader = null;
        TraceContextReadingMiddleware::$sawRoot = null;
    }

    public function test_the_package_auto_discovers_the_provider()
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertContains(TracingServiceProvider::class, $composer['extra']['laravel']['providers'] ?? []);
    }

    public function test_the_context_is_scoped_to_one_unit_of_work()
    {
        $first = app(TraceContext::class);
        $this->assertSame($first, app(TraceContext::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(TraceContext::class));
    }

    public function test_the_request_entry_runs_ahead_of_the_service_middleware()
    {
        $this->assertSame(StartTraceContext::class, $this->app->make(HttpKernel::class)->getGlobalMiddleware()[0]);

        $this->get('/trace/probe', [TraceContext::HEADER => self::INBOUND_HEADER])->assertNoContent();

        $this->assertSame(self::REQUEST_ROOT, TraceContextReadingMiddleware::$sawRoot);
    }

    public function test_a_request_continues_root_parent_and_sampled_from_its_inbound_header()
    {
        $this->get('/trace/probe', [TraceContext::HEADER => self::INBOUND_HEADER])->assertNoContent();

        $this->assertSame(self::REQUEST_ROOT, $this->seen['root']);
        $this->assertSame(self::REQUEST_PARENT, $this->seen['parent']);
        $this->assertSame('1', $this->seen['sampled']);
    }

    public function test_a_job_dispatched_during_a_request_carries_its_root_and_sampled_but_no_parent()
    {
        $this->get('/trace/probe', [TraceContext::HEADER => self::INBOUND_HEADER])->assertNoContent();

        $this->assertSame(self::REQUEST_ROOT, $this->seen['payload'][TraceContext::PAYLOAD_KEY] ?? null);
        $this->assertSame('1', $this->seen['payload'][TraceContext::PAYLOAD_SAMPLED_KEY] ?? null);
    }

    public function test_a_request_with_a_bare_or_malformed_header_mints_a_root_for_its_jobs()
    {
        foreach ([self::REQUEST_ROOT, 'MalformedTraceId', null] as $header) {
            $this->get('/trace/probe', $header === null ? [] : [TraceContext::HEADER => $header])->assertNoContent();

            $minted = $this->seen['root'];
            $this->assertMatchesRegularExpression(self::XRAY_ID, $minted);
            $this->assertNotSame(self::REQUEST_ROOT, $minted);
            $this->assertNull($this->seen['parent']);
            $this->assertNull($this->seen['sampled']);
            $this->assertSame([TraceContext::PAYLOAD_KEY => $minted], array_intersect_key($this->seen['payload'], array_flip([TraceContext::PAYLOAD_KEY, TraceContext::PAYLOAD_SAMPLED_KEY])));
            $this->assertSame($minted, $this->seen['second_payload'][TraceContext::PAYLOAD_KEY]);
        }
    }

    public function test_the_request_entry_writes_no_log_fields()
    {
        $this->get('/trace/probe')->assertNoContent();

        $this->assertArrayNotHasKey(LogFields::TRACE_ID_HEADER, $this->seen['log']);
        $this->assertArrayNotHasKey(LogFields::TRACE_ID, $this->seen['log']);
    }

    public function test_a_sync_job_runs_inside_its_request_trace_and_leaves_it_whole()
    {
        $this->get('/trace/sync-job', [TraceContext::HEADER => self::INBOUND_HEADER])->assertNoContent();

        // The job runs under the request's trace, Parent included, and the
        // request still has its Parent and raw log header afterwards.
        $expected = 'Root='.self::REQUEST_ROOT.';Parent='.self::REQUEST_PARENT.';Sampled=1';
        $this->assertSame($expected, TraceProbeJob::$ranWithHeader);
        $this->assertSame($expected, $this->seen['header']);
        $this->assertSame(self::INBOUND_HEADER, $this->seen['log'][LogFields::TRACE_ID_HEADER]);
    }

    public function test_a_command_called_during_a_request_leaves_the_request_trace_alone()
    {
        $this->get('/trace/command', [TraceContext::HEADER => self::INBOUND_HEADER])->assertNoContent();

        $this->assertSame('Root='.self::REQUEST_ROOT.';Parent='.self::REQUEST_PARENT.';Sampled=1', $this->seen['header']);
        $this->assertSame(self::INBOUND_HEADER, $this->seen['log'][LogFields::TRACE_ID_HEADER]);
    }

    public function test_nothing_is_stamped_when_no_trace_has_started()
    {
        $payload = $this->createPayload();

        $this->assertArrayNotHasKey(TraceContext::PAYLOAD_KEY, $payload);
        $this->assertArrayNotHasKey(TraceContext::PAYLOAD_SAMPLED_KEY, $payload);
    }

    public function test_a_job_queued_with_no_trace_mints_its_own_when_it_starts()
    {
        $payload = $this->createPayload();

        $this->resetLikeTheWorker();
        $this->processJob($payload);

        $minted = app(TraceContext::class)->current();
        $this->assertMatchesRegularExpression(self::XRAY_ID, $minted);
        $this->assertLoggedTraceId($minted);
    }

    public function test_a_real_payload_carries_root_and_sampled_to_the_worker()
    {
        $this->get('/trace/probe', [TraceContext::HEADER => self::INBOUND_HEADER])->assertNoContent();

        $this->resetLikeTheWorker();
        $this->processJob($this->seen['payload']);

        $this->assertSame('Root='.self::REQUEST_ROOT.';Sampled=1', app(TraceContext::class)->header());
        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    public function test_a_payload_from_before_the_sampled_key_restores_its_root()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT]);

        $this->assertSame('Root='.self::REQUEST_ROOT, app(TraceContext::class)->header());
        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    public function test_a_job_dispatched_by_a_job_carries_its_root_and_sampled()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT, TraceContext::PAYLOAD_SAMPLED_KEY => '0']);

        $payload = $this->createPayload();

        $this->assertSame(self::REQUEST_ROOT, $payload[TraceContext::PAYLOAD_KEY] ?? null);
        $this->assertSame('0', $payload[TraceContext::PAYLOAD_SAMPLED_KEY] ?? null);
    }

    public function test_a_job_dispatched_by_a_command_carries_its_root_and_no_sampled()
    {
        $this->startCommand('app:nightly-sync');
        $runRoot = app(TraceContext::class)->current();

        $payload = $this->createPayload();

        $this->assertSame($runRoot, $payload[TraceContext::PAYLOAD_KEY] ?? null);
        $this->assertArrayNotHasKey(TraceContext::PAYLOAD_SAMPLED_KEY, $payload);
    }

    public function test_a_job_arriving_without_a_trace_id_mints_one()
    {
        $this->processJob(['displayName' => 'ExternalMessage']);

        $minted = app(TraceContext::class)->current();
        $this->assertMatchesRegularExpression(self::XRAY_ID, $minted);
        $this->assertLoggedTraceId($minted);
    }

    public function test_a_job_with_an_unusable_trace_id_mints_one_and_drops_its_sampled()
    {
        foreach ([['not' => 'a string'], 'not-an-xray-root', self::REQUEST_ROOT.';Sampled=1'] as $unusable) {
            $this->processJob([TraceContext::PAYLOAD_KEY => $unusable, TraceContext::PAYLOAD_SAMPLED_KEY => '1']);

            $context = app(TraceContext::class);
            $this->assertMatchesRegularExpression(self::XRAY_ID, $context->current());
            $this->assertNotSame(self::REQUEST_ROOT, $context->current());
            $this->assertNull($context->sampled());
        }
    }

    public function test_a_job_with_an_unusable_sampled_keeps_its_root()
    {
        foreach ([true, 1, '?', 'yes'] as $unusable) {
            $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT, TraceContext::PAYLOAD_SAMPLED_KEY => $unusable]);

            $this->assertSame('Root='.self::REQUEST_ROOT, app(TraceContext::class)->header());
        }
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

    public function test_a_job_never_inherits_the_previous_job_trace_even_without_the_worker_reset()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT, TraceContext::PAYLOAD_SAMPLED_KEY => '1']);
        $this->processJob([]);

        $context = app(TraceContext::class);
        $this->assertNotSame(self::REQUEST_ROOT, $context->current());
        $this->assertNull($context->sampled());
        $this->assertLoggedTraceId($context->current());
    }

    public function test_a_job_logs_its_id_when_the_worker_clears_only_the_log_context()
    {
        // The package's WorkCommand builds its Worker with no resetScope: it
        // clears the log context after each job but never forgets scoped
        // instances, so the context still holds the previous job's trace.
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
        $this->assertSame('Root='.$runId, app(TraceContext::class)->header());
        $this->assertLoggedTraceId($runId);
        $this->assertSame($runId, $this->createPayload()[TraceContext::PAYLOAD_KEY]);
        $this->assertSame($runId, $this->createPayload()[TraceContext::PAYLOAD_KEY]);
    }

    public function test_a_command_called_from_another_command_keeps_the_caller_id()
    {
        $this->startCommand('app:nightly-sync');
        $runId = app(TraceContext::class)->current();

        $this->startCommand('app:nested-call');

        $this->assertSame($runId, app(TraceContext::class)->current());
        $this->assertLoggedTraceId($runId);
    }

    public function test_a_command_called_from_inside_a_job_keeps_the_job_trace()
    {
        $this->processJob([TraceContext::PAYLOAD_KEY => self::REQUEST_ROOT, TraceContext::PAYLOAD_SAMPLED_KEY => '1']);

        $this->startCommand('app:nested-call');

        $this->assertSame('Root='.self::REQUEST_ROOT.';Sampled=1', app(TraceContext::class)->header());
        $this->assertLoggedTraceId(self::REQUEST_ROOT);
    }

    /**
     * The payload a job dispatched right now would be queued with.
     */
    private function createPayload(): array
    {
        $queue = $this->app['queue']->connection('sync');

        return json_decode((new \ReflectionMethod($queue, 'createPayload'))->invoke($queue, new TraceProbeJob, 'default'), true);
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
