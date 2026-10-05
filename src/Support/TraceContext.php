<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

/**
 * The trace id of the current unit of work: an HTTP request, a queued job or a
 * console command run.
 *
 * A request's id is the root of its inbound X-Amzn-Trace-Id, the key every
 * service logs as request.amz_trace_id. A job or command has no inbound request
 * to read it from, so TracingServiceProvider carries it across the queue: the
 * dispatcher's id is stamped onto each job payload and restored when a worker
 * picks the job up, and a job that arrives without one, or a command run, gets
 * a freshly minted id. A job therefore shares the id of whatever dispatched it,
 * whether that was a request, another job or a command run.
 *
 * Bound scoped by TracingServiceProvider. Laravel's queue worker builds a new
 * one for every job; a worker without that reset keeps one, and the
 * JobProcessing listener overwrites its id for every queued job.
 */
final class TraceContext
{
    /**
     * Job payload key the dispatcher's trace id travels under.
     */
    public const PAYLOAD_KEY = 'nui_trace_id';

    private ?string $traceId = null;

    /**
     * The id set for this unit of work, else the inbound request's, else null.
     */
    public function current(): ?string
    {
        return $this->traceId ?? self::requestTraceId();
    }

    /**
     * The current id, minting one and keeping it for the rest of this unit of
     * work when there is none, so every job it dispatches shares the same id.
     */
    public function ensure(): string
    {
        $current = $this->current();
        if ($current !== null) {
            return $current;
        }

        return $this->traceId = self::mint();
    }

    public function set(string $traceId): void
    {
        $this->traceId = $traceId;
    }

    /**
     * A new id in X-Ray trace id format, 1-<8 hex epoch seconds>-<24 hex>, so
     * an id minted in a worker rides the same header, and lands in the same log
     * field downstream, as one the gateway issued.
     */
    public static function mint(): string
    {
        return sprintf('1-%08x-%s', time(), bin2hex(random_bytes(12)));
    }

    /**
     * The Root= segment of the inbound X-Amzn-Trace-Id, or the header as-is
     * when it has none: the gateway forwards the bare id.
     */
    private static function requestTraceId(): ?string
    {
        $header = request()?->headers->get('X-Amzn-Trace-Id');
        if (! $header) {
            return null;
        }

        return preg_match('/Root=([^;]+)/', $header, $matches) ? $matches[1] : $header;
    }
}
