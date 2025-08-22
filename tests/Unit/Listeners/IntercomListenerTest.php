<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Events\IntercomEvent;
use NuiMarkets\LaravelSharedUtils\Listeners\IntercomListener;
use NuiMarkets\LaravelSharedUtils\Services\IntercomService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class IntercomListenerTest extends TestCase
{
    private $mockIntercomService;

    private IntercomListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockIntercomService = Mockery::mock(IntercomService::class);
        $this->app->instance(IntercomService::class, $this->mockIntercomService);
        $this->listener = new IntercomListener;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_processes_event_when_service_is_enabled(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            ['product_id' => 'prod-456'],
            'tenant-789'
        );

        $this->mockIntercomService
            ->shouldReceive('isEnabled')
            ->once()
            ->andReturn(true);

        $this->mockIntercomService
            ->shouldReceive('getServiceName')
            ->andReturn('connect-service-test');

        $this->mockIntercomService
            ->shouldReceive('trackEvent')
            ->once()
            ->with(
                'user-123',
                'product_viewed',
                ['product_id' => 'prod-456', 'tenant_id' => 'tenant-789']
            )
            ->andReturn(true);

        Log::shouldReceive('info')
            ->once()
            ->with(
                'IntercomListener processing event',
                Mockery::type('array')
            );

        $this->listener->handle($event);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }

    public function test_handle_skips_processing_when_service_is_disabled(): void
    {
        $event = new IntercomEvent('user-123', 'product_viewed');

        $this->mockIntercomService
            ->shouldReceive('isEnabled')
            ->once()
            ->andReturn(false);

        $this->mockIntercomService
            ->shouldNotReceive('trackEvent');

        $this->listener->handle($event);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }

    public function test_handle_adds_tenant_id_to_properties_when_present(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            ['product_id' => 'prod-456'],
            'tenant-789'
        );

        $this->mockIntercomService
            ->shouldReceive('isEnabled')
            ->andReturn(true);

        $this->mockIntercomService
            ->shouldReceive('getServiceName')
            ->andReturn('connect-service-test');

        $this->mockIntercomService
            ->shouldReceive('trackEvent')
            ->once()
            ->with(
                'user-123',
                'product_viewed',
                ['product_id' => 'prod-456', 'tenant_id' => 'tenant-789']
            )
            ->andReturn(true);

        Log::shouldReceive('info')
            ->once()
            ->with(
                'IntercomListener processing event',
                Mockery::type('array')
            );

        $this->listener->handle($event);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }

    public function test_handle_does_not_add_tenant_id_when_not_present(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            ['product_id' => 'prod-456']
        );

        $this->mockIntercomService
            ->shouldReceive('isEnabled')
            ->andReturn(true);

        $this->mockIntercomService
            ->shouldReceive('getServiceName')
            ->andReturn('connect-service-test');

        $this->mockIntercomService
            ->shouldReceive('trackEvent')
            ->once()
            ->with(
                'user-123',
                'product_viewed',
                ['product_id' => 'prod-456']
            )
            ->andReturn(true);

        Log::shouldReceive('info')
            ->once()
            ->with(
                'IntercomListener processing event',
                Mockery::type('array')
            );

        $this->listener->handle($event);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }

    public function test_handle_logs_warning_when_tracking_fails(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            [],
            'tenant-789'
        );

        $this->mockIntercomService
            ->shouldReceive('isEnabled')
            ->andReturn(true);

        $this->mockIntercomService
            ->shouldReceive('getServiceName')
            ->andReturn('connect-service-test');

        $this->mockIntercomService
            ->shouldReceive('trackEvent')
            ->once()
            ->andReturn(false);

        Log::shouldReceive('info')
            ->once()
            ->with(
                'IntercomListener processing event',
                Mockery::type('array')
            );

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Intercom event tracking failed',
                [
                    'feature' => 'intercom',
                    'service' => 'connect-service-test',
                    'user_id' => 'user-123',
                    'event' => 'product_viewed',
                    'tenant_id' => 'tenant-789',
                ]
            );

        $this->listener->handle($event);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }

    public function test_handle_logs_error_and_continues_when_exception_occurs(): void
    {
        $event = new IntercomEvent('user-123', 'product_viewed');

        $this->mockIntercomService
            ->shouldReceive('isEnabled')
            ->andReturn(true);

        $this->mockIntercomService
            ->shouldReceive('getServiceName')
            ->andReturn('connect-service-test');

        $this->mockIntercomService
            ->shouldReceive('trackEvent')
            ->once()
            ->andThrow(new \Exception('Network timeout'));

        Log::shouldReceive('info')
            ->once()
            ->with(
                'IntercomListener processing event',
                Mockery::type('array')
            );

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Intercom listener exception',
                [
                    'feature' => 'intercom',
                    'service' => 'connect-service-test',
                    'user_id' => 'user-123',
                    'event' => 'product_viewed',
                    'error' => 'Network timeout',
                    'tenant_id' => null,
                ]
            );

        // Should not throw exception
        $this->listener->handle($event);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }

    public function test_failed_logs_job_failure(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            [],
            'tenant-789'
        );

        $exception = new \Exception('Job processing failed');

        $this->mockIntercomService
            ->shouldReceive('getServiceName')
            ->andReturn('connect-service-test');

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Intercom listener job failed',
                [
                    'feature' => 'intercom',
                    'service' => 'connect-service-test',
                    'user_id' => 'user-123',
                    'event' => 'product_viewed',
                    'tenant_id' => 'tenant-789',
                    'error' => 'Job processing failed',
                ]
            );

        $this->listener->failed($event, $exception);

        $this->assertTrue(true); // Assert the method completed without throwing exceptions
    }
}
