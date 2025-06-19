<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Events;

use Nuimarkets\LaravelSharedUtils\Events\IntercomEvent;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class IntercomEventTest extends TestCase
{
    public function test_event_can_be_created_with_required_parameters(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed'
        );

        $this->assertEquals('user-123', $event->userId);
        $this->assertEquals('product_viewed', $event->event);
        $this->assertEquals([], $event->properties);
        $this->assertNull($event->tenantId);
    }

    public function test_event_can_be_created_with_all_parameters(): void
    {
        $properties = [
            'product_id' => 'prod-456',
            'category' => 'meat',
            'price' => 25.99,
        ];

        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            $properties,
            'tenant-789'
        );

        $this->assertEquals('user-123', $event->userId);
        $this->assertEquals('product_viewed', $event->event);
        $this->assertEquals($properties, $event->properties);
        $this->assertEquals('tenant-789', $event->tenantId);
    }

    public function test_event_properties_can_be_empty_array(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'user_logged_in',
            []
        );

        $this->assertEquals([], $event->properties);
    }

    public function test_event_properties_can_contain_nested_data(): void
    {
        $properties = [
            'product' => [
                'id' => 'prod-456',
                'name' => 'Premium Beef',
                'attributes' => [
                    'weight' => '2kg',
                    'grade' => 'A',
                ],
            ],
            'user_context' => [
                'referrer' => 'search',
                'session_duration' => 300,
            ],
        ];

        $event = new IntercomEvent(
            'user-123',
            'product_added_to_cart',
            $properties
        );

        $this->assertEquals($properties, $event->properties);
        $this->assertEquals('prod-456', $event->properties['product']['id']);
        $this->assertEquals('A', $event->properties['product']['attributes']['grade']);
    }

    public function test_event_can_be_serialized(): void
    {
        $event = new IntercomEvent(
            'user-123',
            'product_viewed',
            ['product_id' => 'prod-456'],
            'tenant-789'
        );

        $serialized = serialize($event);
        $unserialized = unserialize($serialized);

        $this->assertInstanceOf(IntercomEvent::class, $unserialized);
        $this->assertEquals('user-123', $unserialized->userId);
        $this->assertEquals('product_viewed', $unserialized->event);
        $this->assertEquals(['product_id' => 'prod-456'], $unserialized->properties);
        $this->assertEquals('tenant-789', $unserialized->tenantId);
    }
}
