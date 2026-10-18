<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;

/**
 * Queued job that records the trace it ran under.
 */
class TraceProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?string $ranWith = null;

    public static ?string $ranWithHeader = null;

    /**
     * Runs inside the job, after it records its trace. Static so the job
     * payload never has to serialize it.
     */
    public static ?\Closure $then = null;

    public function handle(): void
    {
        self::$ranWith = app(TraceContext::class)->current();
        self::$ranWithHeader = app(TraceContext::class)->header();

        if (self::$then !== null) {
            (self::$then)();
        }
    }
}
