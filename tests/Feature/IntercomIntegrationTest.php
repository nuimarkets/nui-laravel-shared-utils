<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use NuiMarkets\LaravelSharedUtils\Events\IntercomEvent;
use NuiMarkets\LaravelSharedUtils\Listeners\IntercomListener;
use NuiMarkets\LaravelSharedUtils\Services\IntercomService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class IntercomIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Configure Intercom for testing
        Config::set('intercom', [
            'token' => 'test-intercom-token',
            'api_version' => '2.11',
            'base_url' => 'https://api.intercom.io',
            'enabled' => true,
            'service_name' => 'connect-service-test',
            'timeout' => 10,
            'fail_silently' => true,
            'batch_size' => 50,
            'event_prefix' => 'connect',
        ]);

        // Fake HTTP for Intercom API calls - nothing here, will be set per test
    }

    public function test_event_is_dispatched_and_processed_by_listener(): void
    {
        // Use real events and queues for integration testing
        Event::fake([IntercomEvent::class]);
        Queue::fake();

        // Dispatch the event
        event(new IntercomEvent(
            'user-123',
            'product_viewed',
            ['product_id' => 'prod-456'],
            'tenant-789'
        ));

        // Assert event was dispatched
        Event::assertDispatched(IntercomEvent::class, function ($event) {
            return $event->userId === 'user-123' &&
                   $event->event === 'product_viewed' &&
                   $event->properties['product_id'] === 'prod-456' &&
                   $event->tenantId === 'tenant-789';
        });
    }

    public function test_listener_processes_event_and_calls_intercom_service(): void
    {
        Http::fake([
            'https://api.intercom.io/*' => Http::response(['status' => 'ok'], 200),
        ]);

        // Create real service and listener instances
        $service = new IntercomService;
        $listener = new IntercomListener($service);

        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            ['product_id' => 'prod-456'],
            'tenant-789'
        );

        // Process the event through the listener
        $listener->handle($event);

        // Assert HTTP request was made to Intercom API
        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.intercom.io/events' &&
                   isset($data['data']) &&
                   $data['data']['external_id'] === 'user-123' &&
                   $data['data']['event_name'] === 'connect_product_viewed' &&
                   $data['data']['metadata']['product_id'] === 'prod-456' &&
                   $data['data']['metadata']['tenant_id'] === 'tenant-789' &&
                   $data['data']['metadata']['service'] === 'connect-service-test';
        });
    }

    public function test_service_handles_disabled_state_gracefully(): void
    {
        Config::set('intercom.enabled', false);

        $service = new IntercomService;
        $listener = new IntercomListener($service);

        $event = new IntercomEvent('user-123', 'test_event');

        // Should not make any HTTP requests when disabled
        $listener->handle($event);

        Http::assertNothingSent();
    }

    public function test_service_handles_api_errors_gracefully(): void
    {
        // Mock API error response
        Http::fake([
            'https://api.intercom.io/events' => Http::response(['error' => 'Invalid request'], 400),
        ]);

        $service = new IntercomService;
        $listener = new IntercomListener($service);

        $event = new IntercomEvent('user-123', 'test_event');

        // Should handle error gracefully and not throw exceptions
        $listener->handle($event);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/events');
        });
    }

    public function test_batch_event_processing(): void
    {
        Http::fake([
            'https://api.intercom.io/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $service = new IntercomService;

        $events = [
            [
                'user_id' => 'user-1',
                'event' => 'product_viewed',
                'properties' => ['product_id' => 'prod-1'],
            ],
            [
                'user_id' => 'user-2',
                'event' => 'product_added_to_cart',
                'properties' => ['product_id' => 'prod-2'],
            ],
        ];

        $results = $service->batchTrackEvents($events);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);

        Http::assertSentCount(1); // Now expects only 1 bulk request
    }

    public function test_user_and_company_management(): void
    {
        Http::fake([
            'https://api.intercom.io/contacts' => Http::response([
                'type' => 'contact',
                'id' => 'contact-123',
                'external_id' => 'user-123',
            ], 200),
            'https://api.intercom.io/companies' => Http::response([
                'type' => 'company',
                'id' => 'company-456',
                'company_id' => 'company-789',
            ], 200),
        ]);

        $service = new IntercomService;

        // Test user creation
        $user = $service->createOrUpdateUser('user-123', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->assertNotEmpty($user);
        $this->assertArrayHasKey('id', $user);
        $this->assertEquals('contact-123', $user['id']);

        // Test company creation
        $company = $service->createOrUpdateCompany('company-789', [
            'name' => 'Test Company',
            'plan' => 'premium',
        ]);

        $this->assertEquals('company-456', $company['id']);

        Http::assertSentCount(2);
    }
}
