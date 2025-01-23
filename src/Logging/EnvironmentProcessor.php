<?php

namespace Nuimarkets\LaravelSharedUtils\Logging;

use Monolog\Processor\ProcessorInterface;

/**
 * Log Processor for environment info etc
 */
class EnvironmentProcessor implements ProcessorInterface
{
    public function __invoke(array $record): array
    {

        $record['extra'] = array_merge($record['extra'], [
            'environment' => env('APP_ENV', 'testing'),
            'hostname' => gethostname(),
            'correlation_id' => request()?->header('X-Request-ID', '') ?? '', // todo use Laravel's request helper
        ]);

        return $record;
    }
}
