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

    public function isHandling(array|LogRecord $record): bool
    {

        if ($this->disabled) {
            return false;
        }

        // Handle both Monolog 2.x array format and 3.x LogRecord
        $level = is_array($record) ? $record['level'] : $record->level->value;
        $context = is_array($record) ? $record['context'] : $record->context;

        // Handle $this->level being either int (Monolog 2) or Level object (Monolog 3)
        $initialLevel = is_object($this->level) ? $this->level->value : $this->level;

        if ($initialLevel === Logger::ERROR && $level >= Logger::ERROR) {
            return true;
        }

        if ($initialLevel === Logger::WARNING && $level == Logger::WARNING) {

            // slack if this flag is included
            $isSlack = isset($context['slack']) && $context['slack'] === true;

            if ($isSlack) {
                return true;
            }
        }

        return false;
    }

    protected function write(array|LogRecord $record): void
    {

        if ($this->disabled) {
            return;
        }

        // Handle both Monolog 2.x array format and 3.x LogRecord
        if (is_array($record)) {
            // Monolog 2.x: array format

            // Filter out null values from extra
            if (isset($record['extra']) && is_array($record['extra'])) {
                $record['extra'] = array_filter($record['extra'], function ($value) {
                    return $value !== null && $value !== '';
                });
            }

            // Remove the slack param from context
            if (isset($record['context']) && is_array($record['context'])) {
                if (isset($record['context']['slack'])) {
                    unset($record['context']['slack']);
                }
            }
        } else {
            // Monolog 3.x: LogRecord format
            $extra = $record->extra;
            $context = $record->context;

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

            // Create new LogRecord with modified data
            $record = $record->with(extra: $extra, context: $context);
        }

        parent::write($record);
    }
}
