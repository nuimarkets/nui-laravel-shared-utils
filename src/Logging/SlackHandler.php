<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

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

    /**
     * Create a new SlackHandler instance.
     *
     * @param  string  $webhookUrl  The Slack webhook URL.
     * @param  int  $level  The minimum logging level.
     * @return void
     */
    public function __construct($webhookUrl, $level)
    {

        if (empty($webhookUrl)) {
            // No webhook configured: disable this handler
            $this->disabled = true;
            // Still set the level property so isHandling(...) can compare if needed
            $this->setLevel($level);

            return;
        }

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
    }

    public function isHandling(LogRecord $record): bool
    {
        if ($this->disabled) {
            return false;
        }

        $level = $record->level->value;
        $context = $record->context;
        $initialLevel = $this->level->value;

        if ($initialLevel === Logger::ERROR && $level >= Logger::ERROR) {
            return true;
        }

        if ($initialLevel === Logger::WARNING && $level == Logger::WARNING) {
            // slack if this flag is included
            return isset($context['slack']) && $context['slack'] === true;
        }

        return false;
    }

    protected function write(LogRecord $record): void
    {
        if ($this->disabled) {
            return;
        }

        $extra = $record->extra;
        $context = $record->context;

        // Filter out null/empty values from extra
        $extra = array_filter($extra, fn ($value) => $value !== null && $value !== '');

        // Remove the slack flag from context (it's only used for routing)
        unset($context['slack']);

        parent::write($record->with(extra: $extra, context: $context));
    }
}
