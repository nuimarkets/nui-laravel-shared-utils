<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Logging\Processors;

use Monolog\LogRecord;
use NuiMarkets\LaravelSharedUtils\Logging\Processors\AddTargetProcessor;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\LoggingTestHelpers;

class AddTargetProcessorTest extends TestCase
{
    use LoggingTestHelpers;

    public function test_adds_target_to_array_record()
    {
        $processor = new AddTargetProcessor('connect-order');

        $record = $this->createLogRecord(context: ['user_id' => 123]);

        $processed = $processor($record);

        $this->assertArrayHasKey('target', $processed['context']);
        $this->assertEquals('connect-order', $processed['context']['target']);
        $this->assertEquals(123, $processed['context']['user_id']);
    }

    public function test_does_not_override_existing_target_by_default()
    {
        $processor = new AddTargetProcessor('connect-order');

        $record = $this->createLogRecord(
            context: [
                'target' => 'existing-target',
                'user_id' => 123,
            ]
        );

        $processed = $processor($record);

        $this->assertEquals('existing-target', $processed['context']['target']);
    }

    public function test_overrides_existing_target_when_enabled()
    {
        $processor = new AddTargetProcessor('connect-order', true);

        $record = $this->createLogRecord(
            context: [
                'target' => 'existing-target',
                'user_id' => 123,
            ]
        );

        $processed = $processor($record);

        $this->assertEquals('connect-order', $processed['context']['target']);
    }

    public function test_handles_empty_context()
    {
        $processor = new AddTargetProcessor('connect-auth');

        $record = $this->createLogRecord();

        $processed = $processor($record);

        $this->assertArrayHasKey('target', $processed['context']);
        $this->assertEquals('connect-auth', $processed['context']['target']);
    }

    public function test_get_and_set_target()
    {
        $processor = new AddTargetProcessor('initial-target');

        $this->assertEquals('initial-target', $processor->getTarget());

        $processor->setTarget('new-target');
        $this->assertEquals('new-target', $processor->getTarget());
    }

    public function test_is_override_enabled()
    {
        $processor1 = new AddTargetProcessor('test', false);
        $this->assertFalse($processor1->isOverrideEnabled());

        $processor2 = new AddTargetProcessor('test', true);
        $this->assertTrue($processor2->isOverrideEnabled());
    }

    public function test_from_config_with_defaults()
    {
        // Mock config function
        $this->app['config']->set('app.name', 'test-app');

        $processor = AddTargetProcessor::fromConfig([]);

        $this->assertEquals('test-app', $processor->getTarget());
        $this->assertFalse($processor->isOverrideEnabled());
    }

    public function test_from_config_with_custom_values()
    {
        $processor = AddTargetProcessor::fromConfig([
            'target' => 'custom-target',
            'override' => true,
        ]);

        $this->assertEquals('custom-target', $processor->getTarget());
        $this->assertTrue($processor->isOverrideEnabled());
    }

    public function test_works_with_monolog3_log_record()
    {
        $this->skipIfMonolog3NotAvailable();

        $processor = new AddTargetProcessor('connect-product');

        $record = $this->createMonolog3Record(context: ['user_id' => 456]);

        $processed = $processor($record);

        $this->assertInstanceOf(LogRecord::class, $processed);
        $this->assertArrayHasKey('target', $processed->context);
        $this->assertEquals('connect-product', $processed->context['target']);
        $this->assertEquals(456, $processed->context['user_id']);
    }

    public function test_preserves_other_log_record_properties()
    {
        $this->skipIfMonolog3NotAvailable();

        $processor = new AddTargetProcessor('connect-surplus');

        $record = $this->createMonolog3Record(
            message: 'Warning message',
            level: self::LOG_LEVEL_WARNING,
            extra: ['custom' => 'extra'],
            channel: 'custom-channel'
        );

        $processed = $processor($record);

        // All other properties should remain unchanged
        $this->assertEquals('custom-channel', $processed->channel);
        $this->assertEquals(\Monolog\Level::Warning, $processed->level);
        $this->assertEquals('Warning message', $processed->message);
        $this->assertEquals(['custom' => 'extra'], $processed->extra);
    }

    public function test_throws_exception_for_invalid_input_type()
    {
        $processor = new AddTargetProcessor('connect-test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AddTargetProcessor expects array or LogRecord, string given');

        $processor('invalid string input');
    }

    public function test_throws_exception_for_object_input()
    {
        $processor = new AddTargetProcessor('connect-test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AddTargetProcessor expects array or LogRecord, stdClass given');

        $processor(new \stdClass);
    }
}
