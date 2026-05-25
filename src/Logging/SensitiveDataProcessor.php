<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

/**
 * Log Processor for sanitizing sensitive data in log records.
 *
 * Automatically redacts sensitive information (tokens, passwords, PII) from
 * `context` and `extra` on Monolog 3 `LogRecord` instances.
 *
 * Implemented as a plain `__invoke()` callable rather than a
 * `ProcessorInterface` to keep the public signature loose; Monolog accepts
 * any callable as a processor.
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
     * Default PII keys to redact when PII redaction is enabled.
     * Based on actual field usage patterns in Connect/NUI applications.
     */
    protected array $piiRedactKeys = [
        // Contact Information (commonly used)
        'email',
        'phone',
        'user_email',

        // Network/System Information
        'ip_address',   // Common Laravel field name
        'client_ip',
        'remote_ip',
        'request.ip',   // LogFields constant
        '.ip',          // Catches request.ip, user.ip, etc.
        'x_forwarded_for', // Raw XFF chain may carry one-or-more IP addresses

        // Personal Identifiers (commonly used)
        'user_id',      // User identifiers are PII
        'customer_id',
        'account_id',

        // Address Information (commonly used in B2B)
        'address',

        // Less Common PII (kept for compliance)
        'mobile',
        'postal_code',
        'zip_code',
        'ssn',
        'social_security',
        'credit_card',
        'card_number',
        'cvv',
        'dob',
        'date_of_birth',

        // Financial Information (B2B context)
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
     * Useful for preserving debugging-friendly fields like 'user_email' or 'ip_address'.
     *
     * @param  array  $fields  Exact field names to preserve (e.g., ['user_email', 'ip_address'])
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
     * Process a Monolog 3 LogRecord.
     */
    public function __invoke(\Monolog\LogRecord $record): \Monolog\LogRecord
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
     * Recursively sanitize sensitive data in arrays
     */
    protected function sanitizeData(array $data): array
    {
        foreach ($data as $key => $value) {
            $shouldRedact = false;
            $keyString = is_string($key) ? $key : (string) $key;
            $keyLower = strtolower($keyString);

            // Check auth fields (always redacted, exact match only)
            foreach ($this->redactKeys as $sensitiveKey) {
                if (str_contains($keyLower, $sensitiveKey)) {
                    $shouldRedact = true;
                    break;
                }
            }

            // Check PII fields (exact match only if PII redaction is enabled)
            if (! $shouldRedact && $this->redactPii) {
                foreach ($this->piiRedactKeys as $piiKey) {
                    if (str_contains($keyLower, $piiKey)) {
                        $shouldRedact = true;
                        break;
                    }
                }
            }

            // Check if field should be preserved (only if not already marked for redaction)
            if ($shouldRedact && $this->shouldPreserveField($keyLower)) {
                // Preserve field even though it would normally be redacted
                $shouldRedact = false;
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
            if ($keyLower === strtolower($preserveField)) {
                return true;
            }
        }

        return false;
    }
}
