<?php

namespace Nuimarkets\LaravelSharedUtils\Services;

use Illuminate\Support\Facades\Log;
use Sentry\Event;

/**
 * Sentry Event Handler
 */
class SentryEventHandler
{
    public static function before(Event $event): ?Event
    {


        if (env('GIT_COMMIT')) {
            $event->setTag('commit', env('GIT_COMMIT'));
        }

        if (env('GIT_BRANCH')) {
            $event->setTag('branch', env('GIT_BRANCH'));
        }

        if (env('GIT_TAG')) {
            $event->setTag('tag', env('GIT_TAG'));
        }

        // Get the exception from the event
        $exception = $event->getExceptions()[0] ?? null;

        Log::debug(
            "Sentry before event",
            [
                'msg' => $event->getMessage(),
                'extra' => $event->getExtra(),
                'exception' => $exception?->getValue(),
            ],
        );

        // Check if it's a FatalError related to timeout
        if ($exception &&
            $exception->getType() === 'Symfony\Component\ErrorHandler\Error\FatalError' &&
            str_contains($exception->getValue(), 'Maximum execution time')) {
            $event->setFingerprint(['timeout-error']);
        }

        return $event;
    }
}
