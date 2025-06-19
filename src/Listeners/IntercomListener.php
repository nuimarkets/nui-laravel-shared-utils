<?php

namespace Nuimarkets\LaravelSharedUtils\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Nuimarkets\LaravelSharedUtils\Events\IntercomEvent;
use Nuimarkets\LaravelSharedUtils\Services\IntercomService;

class IntercomListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(IntercomEvent $event): void
    {
        $intercomService = app(IntercomService::class);

        // Only process if Intercom is enabled
        if (! $intercomService->isEnabled()) {
            return;
        }

        try {
            // Add tenant context if available
            $properties = $event->properties;
            if ($event->tenantId) {
                $properties['tenant_id'] = $event->tenantId;
            }

            // Track the event
            $success = $intercomService->trackEvent(
                $event->userId,
                $event->event,
                $properties
            );

            if (! $success) {
                Log::warning('Intercom event tracking failed', [
                    'service' => $intercomService->getServiceName(),
                    'user_id' => $event->userId,
                    'event' => $event->event,
                    'tenant_id' => $event->tenantId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Intercom listener exception', [
                'service' => $intercomService->getServiceName(),
                'user_id' => $event->userId,
                'event' => $event->event,
                'error' => $e->getMessage(),
                'tenant_id' => $event->tenantId,
            ]);

            // Don't fail the job, just log the error
            // This prevents blocking other operations if Intercom is down
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(IntercomEvent $event, \Throwable $exception): void
    {
        $intercomService = app(IntercomService::class);

        Log::error('Intercom listener job failed', [
            'service' => $intercomService->getServiceName(),
            'user_id' => $event->userId,
            'event' => $event->event,
            'tenant_id' => $event->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
