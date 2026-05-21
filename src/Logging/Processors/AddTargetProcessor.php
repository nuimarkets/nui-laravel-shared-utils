<?php

namespace NuiMarkets\LaravelSharedUtils\Logging\Processors;

use Monolog\LogRecord;

/**
 * Adds a configurable target field to log records for Elasticsearch routing.
 * This processor ensures logs are routed to the correct service-specific index.
 *
 * Background: This implementation fixed an issue where 42.2M logs were incorrectly
 * routed to log-connect-default-* instead of service-specific indexes.
 *
 * Operates on Monolog 3 `LogRecord` instances; implemented as a plain
 * `__invoke()` callable rather than a `ProcessorInterface` to keep the public
 * signature loose.
 */
class AddTargetProcessor
{
    /**
     * The target service name for Elasticsearch routing
     */
    protected string $target;

    /**
     * Whether to override existing target values
     */
    protected bool $overrideExisting;

    /**
     * Create a new AddTargetProcessor instance.
     *
     * @param  string  $target  The target service name (e.g., 'connect-order', 'connect-auth')
     * @param  bool  $overrideExisting  Whether to override if target already exists (default: false)
     */
    public function __construct(string $target, bool $overrideExisting = false)
    {
        $this->target = $target;
        $this->overrideExisting = $overrideExisting;
    }

    /**
     * Add target field for Elasticsearch routing.
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;

        // Only add target if not already present or if override is enabled
        if (! isset($context['target']) || $this->overrideExisting) {
            $context['target'] = $this->target;
        }

        return $record->with(context: $context);
    }

    /**
     * Get the current target value.
     */
    public function getTarget(): string
    {
        return $this->target;
    }

    /**
     * Set a new target value.
     */
    public function setTarget(string $target): self
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Check if override is enabled.
     */
    public function isOverrideEnabled(): bool
    {
        return $this->overrideExisting;
    }

    /**
     * Create a processor instance from configuration.
     *
     * @param  array  $config  Configuration array with 'target' and optional 'override' keys
     */
    public static function fromConfig(array $config): self
    {
        $target = $config['target'] ?? config('app.name', 'laravel');
        $override = $config['override'] ?? false;

        return new self($target, $override);
    }
}
