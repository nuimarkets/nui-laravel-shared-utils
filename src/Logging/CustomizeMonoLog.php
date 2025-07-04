<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

use NuiMarkets\LaravelSharedUtils\Logging\Processors\AddTargetProcessor;

/**
 * Base Monolog customizer that can be extended by services.
 * 
 * This class provides a default configuration that includes:
 * - AddTargetProcessor for Elasticsearch routing
 * - SourceLocationProcessor for debugging
 * - EnvironmentProcessor for environment context
 * - SensitiveDataProcessor for security
 * 
 * Services can extend this class to add their own processors.
 */
class CustomizeMonoLog
{
    /**
     * Customize the Monolog instance.
     * 
     * @param \Monolog\Logger $logger
     * @return void
     */
    public function __invoke($logger): void
    {
        // Add target processor for Elasticsearch routing
        $targetProcessor = $this->createTargetProcessor();
        if ($targetProcessor) {
            $logger->pushProcessor($targetProcessor);
        }
        
        // Add standard processors
        $logger->pushProcessor(new SourceLocationProcessor());
        $logger->pushProcessor(new EnvironmentProcessor());
        $logger->pushProcessor(new SensitiveDataProcessor());
        
        // Add any service-specific processors
        $this->addServiceProcessors($logger);
    }
    
    /**
     * Create the target processor with service-specific configuration.
     * 
     * @return AddTargetProcessor|null
     */
    protected function createTargetProcessor(): ?AddTargetProcessor
    {
        $config = config('logging-utils.processors.add_target', []);
        
        if (!($config['enabled'] ?? true)) {
            return null;
        }
        
        return AddTargetProcessor::fromConfig($config);
    }
    
    /**
     * Add service-specific processors.
     * Services can override this method to add custom processors.
     * 
     * @param \Monolog\Logger $logger
     * @return void
     */
    protected function addServiceProcessors($logger): void
    {
        // Override in service-specific implementations
    }
}