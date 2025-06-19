<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

use Monolog\Handler\SlackWebhookHandler;
use Monolog\Logger;

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
            $this->level = $level;

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

    public function isHandling(array $record): bool
    {

        if ($this->disabled) {
            return false;
        }

        if ($this->level === Logger::ERROR && $record['level'] >= Logger::ERROR) {
            return true;
        }

        if ($this->level === Logger::WARNING && $record['level'] == Logger::WARNING) {

            // slack if this flag is included
            $isSlack = isset($record['context']['slack']) && $record['context']['slack'] === true;

            if ($isSlack) {
                return true;
            }
        }

        return false;
    }

    protected function write(array $record): void
    {

        if ($this->disabled) {
            return;
        }

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

        parent::write($record);
    }
}
