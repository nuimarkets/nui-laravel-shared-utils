<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

/**
 * Log Processor for PHP Source Location
 *
 * Generates debug trace information while preventing field explosion in log storage
 * by limiting the number of frame fields created.
 *
 * Operates on Monolog 3 `LogRecord` instances; implemented as a plain
 * `__invoke()` callable rather than a `ProcessorInterface` to keep the public
 * signature loose.
 */
class SourceLocationProcessor
{
    private int $maxFrames;

    private int $outputFrames;

    /**
     * Create a new SourceLocationProcessor
     *
     * @param  int  $maxFrames  Maximum frames to capture from backtrace (for source detection)
     * @param  int  $outputFrames  Maximum frame_N fields to output (for log storage compatibility)
     */
    public function __construct(int $maxFrames = 10, int $outputFrames = 3)
    {
        $this->maxFrames = max(1, $maxFrames);
        $this->outputFrames = max(1, min($outputFrames, $maxFrames));
    }

    /**
     * Process log record to add source location information.
     */
    public function __invoke(\Monolog\LogRecord $record): \Monolog\LogRecord
    {
        $extra = $record->extra;

        // Limit backtrace to prevent field explosion and improve performance
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxFrames);

        // Safe fallback for base_path() when used outside Laravel
        $base = function_exists('base_path') ? base_path() : '';

        $extra['debug_trace'] = 'Trace count: '.count($trace);

        $debugFrames = array_slice($trace, 0, $this->outputFrames);
        foreach ($debugFrames as $index => $frame) {
            if (isset($frame['file'])) {
                $extra['frame_'.$index] = str_replace($base, '', $frame['file']);
            }
        }

        // Skip internal Laravel/Monolog frames to find actual source
        $sourceFrame = null;
        foreach ($trace as $frame) {
            if (isset($frame['file']) &&
                ! str_contains($frame['file'], 'vendor/laravel') &&
                ! str_contains($frame['file'], 'vendor/monolog')) {
                $sourceFrame = $frame;
                break;
            }
        }

        if ($sourceFrame) {
            $extra['source_file'] = str_replace($base, '', $sourceFrame['file']);
            $extra['source_line'] = $sourceFrame['line'];
        }

        return $record->with(extra: $extra);
    }
}
