<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use NuiMarkets\LaravelSharedUtils\Support\TraceContext;

/**
 * Queued job that records the trace id it ran under.
 */
class TraceProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?string $ranWith = null;

    public function handle(): void
    {
        self::$ranWith = app(TraceContext::class)->current();
    }
}
