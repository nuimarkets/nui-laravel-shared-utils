<?php

namespace NuiMarkets\LaravelSharedUtils\Support;

/**
 * The X-Ray trace of the current unit of work: an HTTP request, a queued job or
 * a console command run.
 *
 * The trace is decided once, where the unit of work starts, and read from here
 * by everything after it:
 *
 * - A request continues its inbound X-Amzn-Trace-Id: Root, Parent and Sampled.
 * - A queued job continues the root and sampling decision of whatever
 *   dispatched it, carried in the job payload. It has no Parent, since its
 *   dispatcher has usually finished by the time it runs.
 * - A command run, and any unit of work with no valid inbound root, mints a
 *   root of its own.
 *
 * Outbound calls send header(), always in X-Ray's Root=...;... form. A bare id
 * is not an X-Ray header: an API Gateway receiving one starts a new trace, so
 * the call would lose its caller's trace id at the first hop.
 *
 * Bound scoped by TracingServiceProvider. Laravel's queue worker builds a new
 * one for every job; a worker without that reset keeps one, and the
 * JobProcessing listener restarts it for every queued job.
 */
final class TraceContext
{
    /**
     * Header the trace travels in, inbound and outbound.
     */
    public const HEADER = 'X-Amzn-Trace-Id';

    /**
     * Job payload key the dispatcher's root travels under.
     */
    public const PAYLOAD_KEY = 'nui_trace_id';

    /**
     * Job payload key the dispatcher's sampling decision travels under, "1" or
     * "0". Absent when the dispatcher had none.
     */
    public const PAYLOAD_SAMPLED_KEY = 'nui_trace_sampled';

    private const ROOT_PATTERN = '/^1-[0-9a-f]{8}-[0-9a-f]{24}$/';

    private const PARENT_PATTERN = '/^[0-9a-f]{16}$/';

    private ?string $root = null;

    private ?string $parent = null;

    private ?string $sampled = null;

    /**
     * The root of this unit of work's trace, or null when nothing has started
     * one (code running outside a request, a job or a command, such as a unit
     * test).
     */
    public function current(): ?string
    {
        return $this->root;
    }

    /**
     * The caller's segment id, set only for a request that received one.
     */
    public function parent(): ?string
    {
        return $this->parent;
    }

    /**
     * The upstream sampling decision, "1" or "0", or null when there was none.
     */
    public function sampled(): ?string
    {
        return $this->sampled;
    }

    /**
     * Start this unit of work's trace, replacing whatever it held.
     *
     * A missing or invalid root starts a new trace with a minted root, and
     * drops the parent and sampling decision with it: they described a trace
     * this unit of work is not continuing. An invalid parent or sampling
     * decision is dropped on its own.
     */
    public function start(?string $root = null, ?string $parent = null, ?string $sampled = null): void
    {
        if ($root === null || ! preg_match(self::ROOT_PATTERN, $root)) {
            $root = self::mint();
            $parent = null;
            $sampled = null;
        }

        $this->root = $root;
        $this->parent = $parent !== null && preg_match(self::PARENT_PATTERN, $parent) ? $parent : null;
        $this->sampled = $sampled === '0' || $sampled === '1' ? $sampled : null;
    }

    /**
     * Start this unit of work's trace from an inbound X-Amzn-Trace-Id.
     *
     * Only Root, Parent and Sampled are kept. Self, Lineage and any other field
     * are dropped: a load balancer adds its own Self, and the rest are not this
     * service's to forward.
     */
    public function startFromHeader(?string $header): void
    {
        $fields = self::parseHeader($header ?? '');

        $this->start($fields['Root'] ?? null, $fields['Parent'] ?? null, $fields['Sampled'] ?? null);
    }

    /**
     * The X-Amzn-Trace-Id to send on an outbound call,
     * Root=<root>[;Parent=<parent>][;Sampled=<0|1>], or null when no trace has
     * been started.
     */
    public function header(): ?string
    {
        if ($this->root === null) {
            return null;
        }

        $header = 'Root='.$this->root;
        if ($this->parent !== null) {
            $header .= ';Parent='.$this->parent;
        }
        if ($this->sampled !== null) {
            $header .= ';Sampled='.$this->sampled;
        }

        return $header;
    }

    /**
     * A new root in X-Ray trace id format, 1-<8 hex epoch seconds>-<24 hex>, so
     * a minted root rides the same header, and lands in the same log field
     * downstream, as one the gateway issued.
     */
    public static function mint(): string
    {
        return sprintf('1-%08x-%s', time(), bin2hex(random_bytes(12)));
    }

    /**
     * The header's fields by name. The first occurrence of a field wins.
     *
     * @return array<string, string>
     */
    private static function parseHeader(string $header): array
    {
        $fields = [];
        foreach (explode(';', $header) as $field) {
            $pair = explode('=', $field, 2);
            if (count($pair) !== 2) {
                continue;
            }

            $name = trim($pair[0]);
            $fields[$name] ??= trim($pair[1]);
        }

        return $fields;
    }
}
