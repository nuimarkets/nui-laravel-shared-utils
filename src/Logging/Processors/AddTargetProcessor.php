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
 * Note: Does not implement ProcessorInterface directly due to incompatible
 * method signatures between Monolog 2.x and 3.x. Monolog accepts any callable
 * as a processor, so this works without implementing the interface.
 */
class AddTargetProcessor
{
    /**
     * The target service name for Elasticsearch routing
     * 
     * @var string
     */
    protected string $target;
    
    /**
     * Whether to override existing target values
     * 
     * @var bool
     */
    protected bool $overrideExisting;
    
    /**
     * Create a new AddTargetProcessor instance.
     * 
     * @param string $target The target service name (e.g., 'connect-order', 'connect-auth')
     * @param bool $overrideExisting Whether to override if target already exists (default: false)
     */
    public function __construct(string $target, bool $overrideExisting = false)
    {
        $this->target = $target;
        $this->overrideExisting = $overrideExisting;
    }
    
    /**
     * Add target field for Elasticsearch routing.
     * 
     * @param array|LogRecord $record The log record
     * @return array|LogRecord The processed log record
     */
    public function __invoke($record)
    {
        // Handle both Monolog 2 (array) and Monolog 3 (LogRecord) formats
        if ($record instanceof LogRecord) {
            return $this->processLogRecord($record);
        }
        
        return $this->processArray($record);
    }
    
    /**
     * Process Monolog 3 LogRecord format.
     * 
     * @param LogRecord $record
     * @return LogRecord
     */
    protected function processLogRecord(LogRecord $record): LogRecord
    {
        $context = $record->context;
        
        // Only add target if not already present or if override is enabled
        if (!isset($context['target']) || $this->overrideExisting) {
            $context['target'] = $this->target;
        }
        
        return $record->with(context: $context);
    }
    
    /**
     * Process Monolog 2 array format.
     * 
     * @param array $record
     * @return array
     */
    protected function processArray(array $record): array
    {
        // Only add target if not already present or if override is enabled
        if (!isset($record['context']['target']) || $this->overrideExisting) {
            $record['context']['target'] = $this->target;
        }
        
        return $record;
    }
    
    /**
     * Get the current target value.
     * 
     * @return string
     */
    public function getTarget(): string
    {
        return $this->target;
    }
    
    /**
     * Set a new target value.
     * 
     * @param string $target
     * @return self
     */
    public function setTarget(string $target): self
    {
        $this->target = $target;
        return $this;
    }
    
    /**
     * Check if override is enabled.
     * 
     * @return bool
     */
    public function isOverrideEnabled(): bool
    {
        return $this->overrideExisting;
    }
    
    /**
     * Create a processor instance from configuration.
     * 
     * @param array $config Configuration array with 'target' and optional 'override' keys
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        $target = $config['target'] ?? config('app.name', 'laravel');
        $override = $config['override'] ?? false;
        
        return new self($target, $override);
    }
}