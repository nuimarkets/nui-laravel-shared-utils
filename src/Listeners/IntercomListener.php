<?php

namespace Nuimarkets\LaravelSharedUtils\Listeners;

use Nuimarkets\LaravelSharedUtils\Events\IntercomEvent;
use Nuimarkets\LaravelSharedUtils\Services\IntercomService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class IntercomListener implements ShouldQueue
{
    use InteractsWithQueue;

    private IntercomService $intercomService;

    /**
     * Create the event listener.
     */
    public function __construct(IntercomService $intercomService)
    {
        $this->intercomService = $intercomService;
    }

    /**
     * Handle the event.
     */
    public function handle(IntercomEvent $event): void
    {
        // Only process if Intercom is enabled
        if (!$this->intercomService->isEnabled()) {
            return;
        }

        try {
            // Add tenant context if available
            $properties = $event->properties;
            if ($event->tenantId) {
                $properties['tenant_id'] = $event->tenantId;
            }

            // Track the event
            $success = $this->intercomService->trackEvent(
                $event->userId,
                $event->event,
                $properties
            );

            if (!$success) {
                Log::warning('Intercom event tracking failed', [
                    'service' => $this->intercomService->getServiceName(),
                    'user_id' => $event->userId,
                    'event' => $event->event,
                    'tenant_id' => $event->tenantId
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Intercom listener exception', [
                'service' => $this->intercomService->getServiceName(),
                'user_id' => $event->userId,
                'event' => $event->event,
                'error' => $e->getMessage(),
                'tenant_id' => $event->tenantId
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
        Log::error('Intercom listener job failed', [
            'service' => $this->intercomService->getServiceName(),
            'user_id' => $event->userId,
            'event' => $event->event,
            'tenant_id' => $event->tenantId,
            'error' => $exception->getMessage()
        ]);
    }
}