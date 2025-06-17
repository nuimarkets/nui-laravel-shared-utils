<?php

namespace Nuimarkets\LaravelSharedUtils\Http\Controllers\Traits;

use Nuimarkets\LaravelSharedUtils\Events\IntercomEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait TracksIntercomEvents
{
    /**
     * Track product view events
     */
    protected function trackProductView(string $productId, array $productDetails = [], ?Request $request = null): void
    {
        if (!$request) {
            return;
        }

        $userId = $request->get('userID');
        if (!$userId) {
            return;
        }

        $properties = [
            'product_id' => $productId,
            'product_details' => $productDetails,
            'controller' => class_basename(static::class),
            'timestamp' => now()->toISOString(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ];

        // Dispatch event for async processing
        event(new IntercomEvent($userId, 'product_viewed', $properties, $request->get('tenant_uuid')));
    }

    /**
     * Track a generic event
     */
    protected function trackEvent(string $event, array $properties = [], ?Request $request = null): void
    {
        if (!$request) {
            return;
        }

        $userId = $request->get('userID');
        if (!$userId) {
            return;
        }

        $defaultProperties = [
            'controller' => class_basename(static::class),
            'timestamp' => now()->toISOString(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
        ];

        $properties = array_merge($defaultProperties, $properties);

        // Dispatch event for async processing
        event(new IntercomEvent($userId, $event, $properties, $request->get('tenant_uuid')));
    }

    /**
     * Track user action events (create, update, delete)
     */
    protected function trackUserAction(string $action, string $resourceType, string $resourceId, array $metadata = [], ?Request $request = null): void
    {
        if (!$request) {
            return;
        }

        $eventName = sprintf('%s_%s', $resourceType, $action);
        
        $properties = [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'action' => $action,
            'metadata' => $metadata,
        ];

        $this->trackEvent($eventName, $properties, $request);
    }
}