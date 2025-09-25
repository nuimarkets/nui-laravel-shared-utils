<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

/**
 * Log Processor for sanitizing sensitive data in log records
 *
 * This processor automatically redacts sensitive information like tokens,
 * passwords, from log records to prevent accidental exposure
 * in logging output.
 *
 * Compatible with both Monolog 2.x (array records) and 3.x (LogRecord objects)
 *
 * Note: Does not implement ProcessorInterface directly due to incompatible
 * method signatures between Monolog 2.x and 3.x. Monolog accepts any callable
 * as a processor, so this works without implementing the interface.
 */
class SensitiveDataProcessor
{
    /**
     * Keys that should be redacted in logging for security and privacy
     */
    protected array $redactKeys = [
        // Authentication & Authorization
        'token',
        'authorization',
        'password',
        'secret',
        'key',
        'jwt',
        'bearer',
    ];

    /**
     * Process log record - compatible with both Monolog 2.x and 3.x
     *
     * @param  array|\Monolog\LogRecord  $record
     * @return array|\Monolog\LogRecord Returns array for Monolog 2.x or LogRecord instance for Monolog 3.x
     */
    public function __invoke($record)
    {
        // Monolog 3.x uses LogRecord objects (if class exists)
        if (class_exists('\Monolog\LogRecord') && $record instanceof \Monolog\LogRecord) {
            return $this->processLogRecord($record);
        }

        // Monolog 2.x uses arrays
        if (is_array($record)) {
            return $this->processArrayRecord($record);
        }

        return $record;
    }

    /**
     * Process Monolog 3.x LogRecord objects
     */
    protected function processLogRecord($record)
    {
        $context = $record->context;
        $extra = $record->extra;

        if (! empty($context)) {
            $context = $this->sanitizeData($context);
        }

        if (! empty($extra)) {
            $extra = $this->sanitizeData($extra);
        }

        return $record->with(context: $context, extra: $extra);
    }

    /**
     * Process Monolog 2.x array records
     */
    protected function processArrayRecord(array $record): array
    {
        if (isset($record['context'])) {
            $record['context'] = $this->sanitizeData($record['context']);
        }

        if (isset($record['extra'])) {
            $record['extra'] = $this->sanitizeData($record['extra']);
        }

        return $record;
    }

    /**
     * Recursively sanitize sensitive data in arrays
     */
    protected function sanitizeData(array $data): array
    {
        foreach ($data as $key => $value) {
            // Check if the current key contains any sensitive keywords
            foreach ($this->redactKeys as $sensitiveKey) {
                if (str_contains(strtolower($key), $sensitiveKey)) {
                    $data[$key] = '[REDACTED]';

                    continue 2; // Skip to next main array element
                }
            }

            // Recursively process nested arrays
            if (is_array($value)) {
                $data[$key] = $this->sanitizeData($value);
            }
        }

        return $data;
    }
}
