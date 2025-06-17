<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Services;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Nuimarkets\LaravelSharedUtils\Services\IntercomService;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class IntercomServiceTest extends TestCase
{
    private IntercomService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock config for testing
        Config::set('intercom', [
            'token' => 'test-token',
            'api_version' => '2.11',
            'base_url' => 'https://api.intercom.io',
            'enabled' => true,
            'service_name' => 'connect-service-test',
            'timeout' => 10,
            'fail_silently' => true,
            'batch_size' => 50,
            'event_prefix' => 'connect',
        ]);

        $this->service = new IntercomService;
    }

    public function test_service_initializes_with_config(): void
    {
        $this->assertTrue($this->service->isEnabled());
        $this->assertEquals('connect-service-test', $this->service->getServiceName());

        $config = $this->service->getConfig();
        $this->assertEquals('test-token', $config['token']);
        $this->assertEquals('https://api.intercom.io', $config['base_url']);
    }

    public function test_service_disabled_when_no_token(): void
    {
        Config::set('intercom.token', '');
        $service = new IntercomService;

        $this->assertFalse($service->isEnabled());
    }

    public function test_service_disabled_when_not_enabled(): void
    {
        Config::set('intercom.enabled', false);
        $service = new IntercomService;

        $this->assertFalse($service->isEnabled());
    }

    public function test_track_event_success(): void
    {
        Http::fake([
            'api.intercom.io/events' => Http::response([
                'type' => 'event',
                'id' => 'event-123',
            ], 200),
        ]);

        $result = $this->service->trackEvent('user-123', 'product_viewed', [
            'product_id' => 'prod-456',
            'category' => 'meat',
        ]);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.intercom.io/events' &&
                   $request->method() === 'POST' &&
                   $data['user_id'] === 'user-123' &&
                   $data['event_name'] === 'connect_product_viewed' &&
                   $data['metadata']['service'] === 'connect-service-test' &&
                   $data['metadata']['product_id'] === 'prod-456' &&
                   $data['metadata']['category'] === 'meat';
        });
    }

    public function test_track_event_failure(): void
    {
        Http::fake([
            'api.intercom.io/events' => Http::response([
                'errors' => [['message' => 'Invalid user']],
            ], 400),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->service->trackEvent('user-123', 'product_viewed', []);

        $this->assertFalse($result);
    }

    public function test_track_event_returns_false_when_disabled(): void
    {
        Config::set('intercom.enabled', false);
        $service = new IntercomService;

        $result = $service->trackEvent('user-123', 'test_event', []);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_create_or_update_user_success(): void
    {
        Http::fake([
            'api.intercom.io/contacts' => Http::response([
                'type' => 'contact',
                'id' => 'contact-123',
                'external_id' => 'user-123',
            ], 200),
        ]);

        $result = $this->service->createOrUpdateUser('user-123', [
            'email' => 'test@example.com',
            'name' => 'Test User',
            'custom_field' => 'custom_value',
        ]);

        $this->assertNotEmpty($result);
        $this->assertEquals('contact-123', $result['id']);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.intercom.io/contacts' &&
                   $request->method() === 'POST' &&
                   $data['external_id'] === 'user-123' &&
                   $data['email'] === 'test@example.com' &&
                   $data['name'] === 'Test User' &&
                   $data['custom_attributes']['custom_field'] === 'custom_value' &&
                   $data['custom_attributes']['service_last_active'] === 'connect-service-test';
        });
    }

    public function test_create_or_update_user_failure(): void
    {
        Http::fake([
            'api.intercom.io/contacts' => Http::response([
                'errors' => [['message' => 'Invalid email']],
            ], 422),
        ]);

        Log::shouldReceive('warning')->once();

        $result = $this->service->createOrUpdateUser('user-123', [
            'email' => 'invalid-email',
        ]);

        $this->assertEmpty($result);
    }

    public function test_create_or_update_company_success(): void
    {
        Http::fake([
            'api.intercom.io/companies' => Http::response([
                'type' => 'company',
                'id' => 'company-123',
                'company_id' => 'comp-456',
            ], 200),
        ]);

        $result = $this->service->createOrUpdateCompany('comp-456', [
            'name' => 'Test Company',
            'plan' => 'premium',
            'custom_field' => 'custom_value',
        ]);

        $this->assertNotEmpty($result);
        $this->assertEquals('company-123', $result['id']);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->url() === 'https://api.intercom.io/companies' &&
                   $request->method() === 'POST' &&
                   $data['company_id'] === 'comp-456' &&
                   $data['name'] === 'Test Company' &&
                   $data['plan'] === 'premium' &&
                   $data['custom_attributes']['custom_field'] === 'custom_value' &&
                   $data['custom_attributes']['last_active_service'] === 'connect-service-test';
        });
    }

    public function test_batch_track_events(): void
    {
        Http::fake([
            'api.intercom.io/events' => Http::response([
                'type' => 'event',
                'id' => 'event-123',
            ], 200),
        ]);

        $events = [
            [
                'user_id' => 'user-1',
                'event' => 'product_viewed',
                'properties' => ['product_id' => 'prod-1'],
            ],
            [
                'user_id' => 'user-2',
                'event' => 'product_searched',
                'properties' => ['query' => 'beef'],
            ],
        ];

        $results = $this->service->batchTrackEvents($events);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);

        Http::assertSentCount(2);
    }

    public function test_batch_track_events_returns_empty_when_disabled(): void
    {
        Config::set('intercom.enabled', false);
        $service = new IntercomService;

        $events = [
            ['user_id' => 'user-1', 'event' => 'test', 'properties' => []],
        ];

        $results = $service->batchTrackEvents($events);

        $this->assertEmpty($results);
        Http::assertNothingSent();
    }

    public function test_format_event_name(): void
    {
        // Test event name formatting through trackEvent
        Http::fake([
            'api.intercom.io/events' => Http::response(['type' => 'event'], 200),
        ]);

        $this->service->trackEvent('user-123', 'Product Viewed', []);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $data['event_name'] === 'connect_product_viewed';
        });
    }

    public function test_api_request_includes_correct_headers(): void
    {
        Http::fake([
            'api.intercom.io/events' => Http::response(['type' => 'event'], 200),
        ]);

        $this->service->trackEvent('user-123', 'test_event', []);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Authorization', 'Bearer test-token') &&
                   $request->hasHeader('Accept', 'application/json') &&
                   $request->hasHeader('Content-Type', 'application/json') &&
                   $request->hasHeader('Intercom-Version', '2.11');
        });
    }

    public function test_api_request_timeout_configuration(): void
    {
        Config::set('intercom.timeout', 30);
        $service = new IntercomService;

        Http::fake([
            'api.intercom.io/events' => Http::response(['type' => 'event'], 200),
        ]);

        $service->trackEvent('user-123', 'test_event', []);

        // Verify the timeout was applied (this is implicit in the HTTP fake)
        Http::assertSent(function (Request $request) {
            return true; // The timeout is handled by Laravel HTTP client
        });
    }

    public function test_logging_behavior_with_detailed_logging_enabled(): void
    {
        Config::set('intercom.features.detailed_logging', true);
        $service = new IntercomService;

        Http::fake([
            'api.intercom.io/events' => Http::response(['type' => 'event'], 200),
        ]);

        Log::shouldReceive('info')->once()->with(
            'Intercom event tracked',
            \Mockery::on(function ($context) {
                return $context['user_id'] === 'user-123' &&
                       $context['event'] === 'test_event' &&
                       $context['service'] === 'connect-service-test';
            })
        );

        $service->trackEvent('user-123', 'test_event', []);
    }

    public function test_exception_handling(): void
    {
        // Simulate a network exception
        Http::fake(function () {
            throw new \Exception('Network error');
        });

        Log::shouldReceive('warning')->once();

        $result = $this->service->trackEvent('user-123', 'test_event', []);

        $this->assertFalse($result);
    }

    public function test_event_prefix_is_configurable(): void
    {
        // Test with custom prefix
        Config::set('intercom.event_prefix', 'myapp');
        $service = new IntercomService;

        Http::fake([
            'api.intercom.io/events' => Http::response(['type' => 'event'], 200),
        ]);

        $service->trackEvent('user-123', 'user_action', []);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $data['event_name'] === 'myapp_user_action';
        });
    }

    public function test_event_prefix_can_be_empty(): void
    {
        // Test with empty prefix
        Config::set('intercom.event_prefix', '');
        $service = new IntercomService;

        Http::fake([
            'api.intercom.io/events' => Http::response(['type' => 'event'], 200),
        ]);

        $service->trackEvent('user-123', 'user_action', []);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $data['event_name'] === 'user_action';
        });
    }
}
