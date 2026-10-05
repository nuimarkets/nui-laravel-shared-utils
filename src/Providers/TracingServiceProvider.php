<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;

/**
 * Gives queued jobs and console commands a trace id, so their logs and
 * outbound RemoteRepository calls join the request or run that caused them.
 *
 * - A job carries the id of whatever dispatched it: the request's
 *   X-Amzn-Trace-Id root, the parent job's id, or the command run's id.
 * - A job arriving without one (pushed by another producer, or queued before
 *   this provider was registered) mints a fresh id.
 * - A command run mints one id for the whole run, so a scheduled command gets
 *   a new id each time the scheduler starts it.
 *
 * The id goes into the log context as request.amz_trace_id, the field every
 * service's request logs carry, and as the legacy request.trace_id, so a query
 * on either field finds the job's lines.
 */
class TracingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: Laravel's queue worker forgets scoped
        // instances before every job. A worker built without that reset (this
        // package's WorkCommand) keeps one instance for its whole life, so the
        // JobProcessing listener below sets the id for every queued job rather
        // than relying on a fresh instance.
        $this->app->scoped(TraceContext::class);
    }

    public function boot(): void
    {
        // The payload hooks are static and outlive this application instance,
        // so resolve the context when the hook runs rather than capturing it.
        Queue::createPayloadUsing(static function (): array {
            $context = app(TraceContext::class);
            $minted = $context->current() === null;
            $traceId = $context->ensure();

            // Nothing upstream carried an id (a request with no inbound
            // header), so this unit of work's logs carry none. From here on
            // they carry the one its jobs and outbound calls do, which also
            // covers a sync job, since it runs inside this unit of work.
            if ($minted) {
                self::addToLogContext($traceId);
            }

            return [TraceContext::PAYLOAD_KEY => $traceId];
        });

        $this->app['events']->listen(JobProcessing::class, static function (JobProcessing $event): void {
            $traceId = $event->job->payload()[TraceContext::PAYLOAD_KEY] ?? null;
            if (! is_string($traceId) || $traceId === '') {
                $traceId = TraceContext::mint();
            }

            $context = app(TraceContext::class);

            // A sync job runs inside its dispatcher, whose logs already carry
            // this id. Re-adding it would replace a request's raw
            // X-Amzn-Trace-Id in the log context with its bare root. Only a
            // sync job may skip: a worker that clears the log context between
            // jobs without forgetting scoped instances (this package's
            // WorkCommand) still holds the previous job's id here, and a job
            // sharing that id would otherwise log none.
            if ($event->job instanceof SyncJob && $context->current() === $traceId) {
                return;
            }

            $context->set($traceId);
            self::addToLogContext($traceId);
        });

        // ensure(), not mint(): a command started from inside another one with
        // Artisan::call() stays on its caller's id. Only a run that starts with
        // no id writes the log context: a caller (a request, a job or an outer
        // run) has already put its own there, and a request's is the raw
        // X-Amzn-Trace-Id, which the bare root would replace.
        $this->app['events']->listen(CommandStarting::class, static function (): void {
            $context = app(TraceContext::class);
            if ($context->current() === null) {
                self::addToLogContext($context->ensure());
            }
        });
    }

    private static function addToLogContext(string $traceId): void
    {
        Log::withContext([
            LogFields::TRACE_ID_HEADER => $traceId,
            LogFields::TRACE_ID => $traceId,
        ]);
    }
}
