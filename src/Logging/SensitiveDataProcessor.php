<?php

namespace Nuimarkets\LaravelSharedUtils\Logging;

use Monolog\Processor\ProcessorInterface;

/**
 * Log Processor for sanitizing sensitive data in log records
 *
 * This processor automatically redacts sensitive information like tokens,
 * passwords, from log records to prevent accidental exposure
 * in logging output.
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    /**
     * Keys that should be redacted in logging for security
     */
    protected array $redactKeys = [
        'token',
        'authorization',
        'password',
        'secret',
    ];

    /**
     * @param array $record
     * @return array
     */
    public function __invoke(array $record): array
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
     *
     * @param array $data
     * @return array
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