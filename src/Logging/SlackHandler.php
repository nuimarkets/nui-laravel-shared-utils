<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

use Monolog\Handler\MissingExtensionException;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Slack Handler
 * These can be added to "stack" channel so logging is centralised
 * Ie logging to separate channels is discouraged to ensure logs are centralised.
 * Error = Always report
 * Warning = Report if "slack" param exists in context
 *
 * 'slackError' => [
 *   'driver' => 'monolog',
 *   'handler' => SlackHandler::class,
 *   'with' => [
 *   'webhookUrl' => env('SLACK_ERROR_CHANNEL'),
 *   'level' => Logger::ERROR,
 *   ],
 * ],
 *
 * 'slackWarn' => [
 *   'driver' => 'monolog',
 *   'handler' => SlackHandler::class,
 *   'with' => [
 *   'webhookUrl' => env('SLACK_WARN_CHANNEL'),
 *   'level' => Logger::WARNING,
 *   ],
 * ],
 */
class SlackHandler extends SlackWebhookHandler
{
    protected bool $disabled = false;

    protected int $configuredLevel;

    /**
     * Create a new SlackHandler instance.
     *
     * @param  string  $webhookUrl  The Slack webhook URL.
     * @param  int  $level  The minimum logging level.
     * @return void
     */
    public function __construct($webhookUrl, $level)
    {
        $this->configuredLevel = $level;

        if (empty($webhookUrl)) {
            // No webhook configured: disable this handler
            $this->disabled = true;

            return;
        }

        try {
            parent::__construct(
                $webhookUrl,
                null,
                config('app.name').'.'.config('app.env'),
                true,
                null,
                false,
                true,
                $level,
                true,
            );
        } catch (MissingExtensionException $e) {
            // Log the issue and disable the handler gracefully
            error_log('SlackHandler disabled: Missing required PHP extension (curl). '.$e->getMessage());
            $this->disabled = true;
        }
    }

    public function isHandling(LogRecord|array $record): bool
    {
        if ($this->disabled) {
            return false;
        }

        // Handle both Monolog 2.x (array) and 3.x (LogRecord) formats
        $level = $record instanceof LogRecord ? $record->level->value : $record['level'];
        $context = $record instanceof LogRecord ? $record->context : $record['context'];

        // Use our stored level integer for comparison
        if ($this->configuredLevel === Logger::ERROR && $level >= Logger::ERROR) {
            return true;
        }

        if ($this->configuredLevel === Logger::WARNING && $level == Logger::WARNING) {
            // slack if this flag is included
            $isSlack = isset($context['slack']) && $context['slack'] === true;

            if ($isSlack) {
                return true;
            }
        }

        return false;
    }

    protected function write(LogRecord|array $record): void
    {
        if ($this->disabled) {
            return;
        }

        // Handle both Monolog 2.x (array) and 3.x (LogRecord) formats
        if ($record instanceof LogRecord) {
            // For Monolog 3.x LogRecord, we need to work with the object properties
            $context = $record->context;
            $extra = $record->extra;

            // Filter out null values from extra
            if (is_array($extra)) {
                $extra = array_filter($extra, function ($value) {
                    return $value !== null && $value !== '';
                });
            }

            // Remove the slack param from context
            if (is_array($context) && isset($context['slack'])) {
                unset($context['slack']);
            }

            // Create a new LogRecord with modified context and extra
            $record = new LogRecord(
                $record->datetime,
                $record->channel,
                $record->level,
                $record->message,
                $context,
                $extra,
                $record->formatted
            );
        } else {
            // For Monolog 2.x array format
            // Filter out null values from context
            if (isset($record['extra']) && is_array($record['extra'])) {
                $record['extra'] = array_filter($record['extra'], function ($value) {
                    return $value !== null && $value !== '';
                });
            }

            // Remove the slack param
            if (isset($record['context']) && is_array($record['context'])) {
                if (isset($record['context']['slack'])) {
                    unset($record['context']['slack']);
                }
            }
        }

        parent::write($record);
    }
}
