<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Http\Controllers\Traits;

use Nuimarkets\LaravelSharedUtils\Events\IntercomEvent;
use Nuimarkets\LaravelSharedUtils\Http\Controllers\Traits\TracksIntercomEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class TracksIntercomEventsTest extends TestCase
{
    use TracksIntercomEvents;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    public function test_track_product_view_dispatches_event_with_user_from_request(): void
    {
        $request = Request::create('/test', 'GET', [
            'userID' => 'user-123',
            'tenant_uuid' => 'tenant-789'
        ]);
        $request->headers->set('User-Agent', 'Mozilla/5.0');

        $this->trackProductView('prod-456', ['name' => 'Test Product'], $request);

        Event::assertDispatched(IntercomEvent::class, function ($event) {
            return $event->userId === 'user-123' &&
                   $event->event === 'product_viewed' &&
                   $event->properties['product_id'] === 'prod-456' &&
                   $event->properties['product_details']['name'] === 'Test Product' &&
                   $event->properties['controller'] === 'TracksIntercomEventsTest' &&
                   $event->tenantId === 'tenant-789';
        });
    }

    public function test_track_product_view_includes_common_properties(): void
    {
        $request = Request::create('/test', 'GET', ['userID' => 'user-123'], [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'REMOTE_ADDR' => '192.168.1.1'
        ]);

        $this->trackProductView('prod-456', [], $request);

        Event::assertDispatched(IntercomEvent::class, function ($event) {
            return isset($event->properties['controller']) &&
                   isset($event->properties['timestamp']) &&
                   isset($event->properties['user_agent']) &&
                   isset($event->properties['ip_address']) &&
                   $event->properties['user_agent'] === 'Mozilla/5.0' &&
                   $event->properties['ip_address'] === '192.168.1.1';
        });
    }

    public function test_track_product_view_does_not_dispatch_when_no_user_id(): void
    {
        $request = Request::create('/test', 'GET');
        // No userID parameter

        $this->trackProductView('prod-456', [], $request);

        Event::assertNotDispatched(IntercomEvent::class);
    }

    public function test_track_product_view_does_not_dispatch_when_no_request(): void
    {
        $this->trackProductView('prod-456', []);

        Event::assertNotDispatched(IntercomEvent::class);
    }

    public function test_track_event_dispatches_generic_event(): void
    {
        $request = Request::create('/test', 'GET', [
            'userID' => 'user-123',
            'tenant_uuid' => 'tenant-789'
        ]);

        $this->trackEvent('user_logged_in', ['source' => 'web'], $request);

        Event::assertDispatched(IntercomEvent::class, function ($event) {
            return $event->userId === 'user-123' &&
                   $event->event === 'user_logged_in' &&
                   $event->properties['source'] === 'web' &&
                   $event->tenantId === 'tenant-789';
        });
    }

    public function test_track_event_merges_default_properties(): void
    {
        $request = Request::create('/test', 'GET', ['userID' => 'user-123']);

        $this->trackEvent('test_event', ['custom' => 'value'], $request);

        Event::assertDispatched(IntercomEvent::class, function ($event) {
            return isset($event->properties['controller']) &&
                   isset($event->properties['timestamp']) &&
                   isset($event->properties['user_agent']) &&
                   isset($event->properties['ip_address']) &&
                   $event->properties['custom'] === 'value';
        });
    }

    public function test_track_user_action_formats_event_name(): void
    {
        $request = Request::create('/test', 'GET', ['userID' => 'user-123']);

        $this->trackUserAction('created', 'product', 'prod-456', ['name' => 'Test'], $request);

        Event::assertDispatched(IntercomEvent::class, function ($event) {
            return $event->event === 'product_created' &&
                   $event->properties['resource_type'] === 'product' &&
                   $event->properties['resource_id'] === 'prod-456' &&
                   $event->properties['action'] === 'created' &&
                   $event->properties['metadata']['name'] === 'Test';
        });
    }

    public function test_track_user_action_does_not_dispatch_without_request(): void
    {
        $this->trackUserAction('created', 'product', 'prod-456');

        Event::assertNotDispatched(IntercomEvent::class);
    }
}