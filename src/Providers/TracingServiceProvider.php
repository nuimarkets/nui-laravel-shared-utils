<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\StartTraceContext;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;

/**
 * Starts a trace for every unit of work, in TraceContext, at the one place each
 * kind of work begins:
 *
 * - An HTTP request continues its inbound X-Amzn-Trace-Id (StartTraceContext,
 *   prepended to the global middleware stack).
 * - A queued job continues the root and sampling decision of whatever
 *   dispatched it, carried in its payload, and mints a root when the payload
 *   has none (pushed by another producer, or queued before this provider was
 *   registered).
 * - A command run mints one root for the whole run, so a scheduled command
 *   gets a new root each time the scheduler starts it.
 *
 * Everything after the entry reads TraceContext: the payload hook stamps its
 * root onto each job dispatched, and RemoteRepository sends its header.
 *
 * A job's or command's root goes into the log context as request.amz_trace_id,
 * the field every service's request logs carry, and as the legacy
 * request.trace_id, so a query on either field finds the job's lines. A
 * request's log fields stay RequestLoggingMiddleware's.
 *
 * Auto-discovered through the package's composer.json, so a service that
 * requires the package gets it without listing it.
 */
class TracingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: Laravel's queue worker forgets scoped
        // instances before every job. A worker built without that reset (this
        // package's WorkCommand) keeps one instance for its whole life, so the
        // JobProcessing listener below restarts it for every queued job rather
        // than relying on a fresh instance.
        $this->app->scoped(TraceContext::class);
    }

    public function boot(): void
    {
        // First, so anything later in the stack, a service's own logging
        // middleware included, reads the request's trace. The kernel is
        // resolved before providers boot when serving a request, so this runs
        // straight away there; a console process never resolves it.
        $this->callAfterResolving(HttpKernel::class, static function (HttpKernel $kernel): void {
            if ($kernel instanceof Kernel) {
                $kernel->prependMiddleware(StartTraceContext::class);
            }
        });

        // The payload hooks are static and outlive this application instance,
        // so resolve the context when the hook runs rather than capturing it.
        // Nothing is stamped when no trace has started: a queued job then
        // mints its own root when it starts, and a sync job runs with none.
        Queue::createPayloadUsing(static function (): array {
            $context = app(TraceContext::class);
            if ($context->current() === null) {
                return [];
            }

            $payload = [TraceContext::PAYLOAD_KEY => $context->current()];
            if ($context->sampled() !== null) {
                $payload[TraceContext::PAYLOAD_SAMPLED_KEY] = $context->sampled();
            }

            return $payload;
        });

        $this->app['events']->listen(JobProcessing::class, static function (JobProcessing $event): void {
            $payload = $event->job->payload();
            $root = $payload[TraceContext::PAYLOAD_KEY] ?? null;
            $sampled = $payload[TraceContext::PAYLOAD_SAMPLED_KEY] ?? null;

            $context = app(TraceContext::class);

            // A sync job runs inside its dispatcher, whose trace this already
            // is, or no trace when its dispatcher has none. Restarting it would
            // drop a request's Parent for the rest of the request, re-adding
            // the log fields would replace its raw X-Amzn-Trace-Id with the
            // bare root, and minting would hand the dispatcher a trace it never
            // started. Only a sync job may skip: a worker that clears the log
            // context between jobs without forgetting scoped instances (this
            // package's WorkCommand) still holds the previous job's trace here,
            // and a job sharing that root would otherwise log none.
            if ($event->job instanceof SyncJob && $context->current() === $root) {
                return;
            }

            $context->start(is_string($root) ? $root : null, null, is_string($sampled) ? $sampled : null);
            self::addToLogContext($context->current());
        });

        // Only a run that starts with no trace starts one: a command started
        // from inside another unit of work with Artisan::call() stays on its
        // caller's root, and its caller has already put its own log fields
        // there (a request's being the raw X-Amzn-Trace-Id, which the bare
        // root would replace).
        $this->app['events']->listen(CommandStarting::class, static function (): void {
            $context = app(TraceContext::class);
            if ($context->current() === null) {
                $context->start();
                self::addToLogContext($context->current());
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
