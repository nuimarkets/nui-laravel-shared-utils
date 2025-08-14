<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use NuiMarkets\LaravelSharedUtils\Exceptions\BadHttpRequestException;
use Sentry\Severity;
use Sentry\State\Scope;

use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;

/**
 * Sentry Error Handler with support for tags and exceptions
 */
class SentryHandler extends AbstractProcessingHandler
{
    /**
     * @param  LogRecord|array  $record
     */
    protected function write($record): void
    {

        if (app()->bound('sentry')) {
            // Handle both Monolog 2.x array format and 3.x LogRecord (laravel >=10)
            $context = is_array($record) ? $record['context'] : $record->context;
            $message = is_array($record) ? $record['message'] : $record->message;
            $level = is_array($record) ? $record['level'] : $record->level->value;

            // Configure Sentry scope with any tags from context
            configureScope(function (Scope $scope) use ($context): void {
                // If there are tags in the context
                if (isset($context['tags']) && is_array($context['tags'])) {
                    $scope->setTags($context['tags']);
                }

                // If the error has tags (from BadHttpRequestException)
                if (isset($context['exception'])
                    && $context['exception'] instanceof BadHttpRequestException
                ) {
                    if (! empty($context['exception']->getTags())) {
                        $scope->setTags($context['exception']->getTags());
                    }

                    if (! empty($context['exception']->getExtra())) {
                        $scope->setExtras($context['exception']->getExtra());
                    }
                }

            });

            // If there's an exception in the context, capture it
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                // Report on the previous otherwise everything in sentry will show as BadHttpRequestException
                if ($context['exception'] instanceof BadHttpRequestException && ! empty($context['exception']->getPrevious())) {
                    captureException($context['exception']->getPrevious());
                } else {
                    captureException($context['exception']);
                }

            } else {
                captureMessage($message, $this->getMonologLevelToSeverity($level));
            }
        }
    }

    /**
     * Map Monolog levels to Sentry Severity
     */
    protected function getMonologLevelToSeverity(int $level): Severity
    {
        return match ($level) {
            Logger::DEBUG => Severity::debug(),
            Logger::INFO => Severity::info(),
            Logger::WARNING => Severity::warning(),
            Logger::CRITICAL => Severity::fatal(),
            default => Severity::error(),
        };
    }
}
