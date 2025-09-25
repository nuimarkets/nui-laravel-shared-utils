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
     */
    public function __invoke(\Monolog\Logger $logger): void
    {
        // Add target processor for Elasticsearch routing
        $targetProcessor = $this->createTargetProcessor();
        if ($targetProcessor) {
            $logger->pushProcessor($targetProcessor);
        }

        // Add standard processors
        $logger->pushProcessor($this->createSourceLocationProcessor());
        $logger->pushProcessor($this->createEnvironmentProcessor());
        $logger->pushProcessor($this->createSensitiveDataProcessor());

        // Add any service-specific processors
        $this->addServiceProcessors($logger);
    }

    /**
     * Create the target processor with service-specific configuration.
     */
    protected function createTargetProcessor(): ?AddTargetProcessor
    {
        $config = config('logging-utils.processors.add_target', []);

        if (! ($config['enabled'] ?? true)) {
            return null;
        }

        return AddTargetProcessor::fromConfig($config);
    }

    /**
     * Create the source location processor.
     * Services can override this to customize source location behavior.
     */
    protected function createSourceLocationProcessor(): SourceLocationProcessor
    {
        return new SourceLocationProcessor;
    }

    /**
     * Create the environment processor.
     * Services can override this to customize environment context.
     */
    protected function createEnvironmentProcessor(): EnvironmentProcessor
    {
        return new EnvironmentProcessor;
    }

    /**
     * Create the sensitive data processor with service-specific configuration.
     * Services can override this to customize field preservation.
     */
    protected function createSensitiveDataProcessor(): SensitiveDataProcessor
    {
        $cfg = (array) config('logging-utils.processors.sensitive_data', []);
        $preserve = $cfg['preserve_fields'] ?? [];
        $redactPii = $cfg['redact_pii'] ?? true;

        return new SensitiveDataProcessor($preserve, (bool) $redactPii);
    }

    /**
     * Add service-specific processors.
     * Services can override this method to add custom processors.
     *
     * @param  \Monolog\Logger  $logger
     */
    protected function addServiceProcessors($logger): void
    {
        // Override in service-specific implementations
    }
}
