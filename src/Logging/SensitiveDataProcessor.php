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
        'jwt',
        'bearer',
        // Specific key patterns to avoid false positives
        'api_key',
        'secret_key',
        'private_key',
        'signing_key',
        'access_key',
    ];

    /**
     * Additional PII fields that can be redacted when privacy mode is enabled
     */
    protected array $piiRedactKeys = [
        // Personal Identifiable Information (PII)
        'email',
        'phone',
        'mobile',
        'ip', // IP addresses are PII under GDPR
        'user_id', // User identifiers are PII
        'ssn',
        'social_security',
        'credit_card',
        'card_number',
        'cvv',
        'dob',
        'date_of_birth',
        'address',
        'postal_code',
        'zip_code',

        // Financial Information
        'bank_account',
        'routing_number',
        'iban',
        'account_number',
    ];

    /**
     * Fields to preserve from redaction (debugging-friendly fields)
     */
    protected array $preserveFields = [];

    /**
     * Whether to include PII fields in redaction
     */
    protected bool $redactPii = true;

    /**
     * Create a new SensitiveDataProcessor instance.
     *
     * @param  array  $preserveFields  Fields to preserve from redaction (for debugging)
     * @param  bool  $redactPii  Whether to redact PII fields in addition to auth fields
     */
    public function __construct(array $preserveFields = [], bool $redactPii = true)
    {
        $this->preserveFields = $preserveFields;
        $this->redactPii = $redactPii;
    }

    /**
     * Configure which fields to preserve from redaction.
     * Useful for preserving debugging-friendly fields like 'email' or 'ip_address'.
     *
     * @param  array  $fields  Field substrings to preserve (e.g., ['email', 'ip'])
     */
    public function preserveFields(array $fields): self
    {
        $this->preserveFields = $fields;

        return $this;
    }

    /**
     * Enable or disable PII field redaction.
     *
     * @param  bool  $enabled  Whether to redact PII fields
     */
    public function enablePiiRedaction(bool $enabled = true): self
    {
        $this->redactPii = $enabled;

        return $this;
    }

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
            $shouldRedact = false;
            $keyString = is_string($key) ? $key : (string) $key;
            $keyLower = strtolower($keyString);

            // Check if field should be preserved from redaction
            if ($this->shouldPreserveField($keyLower)) {
                // Skip redaction for preserved fields
            } else {
                // Check auth fields (always redacted)
                foreach ($this->redactKeys as $sensitiveKey) {
                    if (str_contains($keyLower, $sensitiveKey)) {
                        $shouldRedact = true;
                        break;
                    }
                }

                // Check PII fields (only if PII redaction is enabled)
                if (! $shouldRedact && $this->redactPii) {
                    foreach ($this->piiRedactKeys as $piiKey) {
                        if (str_contains($keyLower, $piiKey)) {
                            $shouldRedact = true;
                            break;
                        }
                    }
                }
            }

            if ($shouldRedact) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                // Recursively process nested arrays
                $data[$key] = $this->sanitizeData($value);
            }
        }

        return $data;
    }

    /**
     * Check if a field should be preserved from redaction.
     *
     * @param  string  $keyLower  Lowercase field key
     * @return bool True if field should be preserved
     */
    protected function shouldPreserveField(string $keyLower): bool
    {
        foreach ($this->preserveFields as $preserveField) {
            if (str_contains($keyLower, strtolower($preserveField))) {
                return true;
            }
        }

        return false;
    }
}
