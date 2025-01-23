<?php

namespace Nuimarkets\LaravelSharedUtils\Logging;

use Monolog\Processor\ProcessorInterface;

/**
 * Log Processor for PHP Source Location
 */
class SourceLocationProcessor implements ProcessorInterface
{

    public function __invoke(array $record): array
    {

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        $record['extra']['debug_trace'] = "Trace count: " . count($trace);

        $debugFrames = array_slice($trace, 0, 3);
        foreach ($debugFrames as $index => $frame) {
            if (isset($frame['file'])) {
                $record['extra']['frame_' . $index] = str_replace(base_path(), '', $frame['file']);
            }
        }

        // Skip internal Laravel/Monolog frames
        $sourceFrame = null;
        foreach ($trace as $frame) {
            if (isset($frame['file']) &&
                !str_contains($frame['file'], 'vendor/laravel') &&
                !str_contains($frame['file'], 'vendor/monolog')) {
                $sourceFrame = $frame;
                break;
            }
        }

        if ($sourceFrame) {
            $record['extra']['source_file'] = str_replace(base_path(), '', $sourceFrame['file']);
            $record['extra']['source_line'] = $sourceFrame['line'];
        }

        return $record;
    }
}