<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Logging;

use Monolog\Logger;
use NuiMarkets\LaravelSharedUtils\Logging\ColoredJsonLineFormatter;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\LoggingTestHelpers;

class ColoredJsonLineFormatterTest extends TestCase
{
    use LoggingTestHelpers;

    private ColoredJsonLineFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ColoredJsonLineFormatter;
    }

    /** @test */
    public function test_formats_monolog_2_array_record_correctly()
    {
        $record = $this->createLogRecord(
            context: ['key' => 'value'],
            extra: ['source_file' => '/path/to/TestClass.php']
        );

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('TestClass', $output);
        $this->assertStringContainsString('INFO', $output);
        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString('key', $output);
        $this->assertStringContainsString('value', $output);
    }

    /** @test */
    public function test_formats_monolog_3_log_record_correctly()
    {
        $this->skipIfMonolog3NotAvailable();

        $record = $this->createMonolog3Record(
            context: ['key' => 'value'],
            extra: ['source_file' => '/path/to/TestClass.php']
        );

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('TestClass', $output);
        $this->assertStringContainsString('INFO', $output);
        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString('key', $output);
        $this->assertStringContainsString('value', $output);
    }

    /** @test */
    public function test_handles_empty_context_gracefully()
    {
        $record = $this->createLogRecord();

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString('INFO', $output);
        // Should not contain context formatting
        $this->assertStringNotContainsString('key', $output);
    }

    /** @test */
    public function test_extracts_class_name_from_source_file()
    {
        $record = $this->createLogRecord(
            extra: ['source_file' => '/app/src/Services/UserServiceTrait.php']
        );

        $output = $this->formatter->format($record);

        // Should extract 'UserService' (removing 'Trait' suffix)
        $this->assertStringContainsString('UserService', $output);
        $this->assertStringNotContainsString('UserServiceTrait', $output);
    }

    /** @test */
    public function test_handles_missing_source_file()
    {
        $record = $this->createLogRecord();

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString('INFO', $output);
        // Should handle gracefully without errors
        $this->assertIsString($output);
    }

    /** @test */
    public function test_formats_nested_context_data()
    {
        $record = $this->createLogRecord(
            context: [
                'user' => [
                    'id' => 123,
                    'name' => 'John Doe',
                ],
                'metadata' => [
                    'action' => 'login',
                    'ip' => '192.168.1.1',
                ],
            ]
        );

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('user', $output);
        $this->assertStringContainsString('123', $output);
        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('metadata', $output);
        $this->assertStringContainsString('login', $output);
        $this->assertStringContainsString('192.168.1.1', $output);
    }

    /** @test */
    public function test_handles_different_log_levels()
    {
        $levels = [
            self::LOG_LEVEL_DEBUG => 'DEBUG',
            self::LOG_LEVEL_INFO => 'INFO',
            self::LOG_LEVEL_WARNING => 'WARNING',
            self::LOG_LEVEL_ERROR => 'ERROR',
            self::LOG_LEVEL_CRITICAL => 'CRITICAL',
        ];

        foreach ($levels as $level => $name) {
            $record = $this->createLogRecord(level: $level);

            $output = $this->formatter->format($record);

            $this->assertStringContainsString($name, $output);
        }
    }

    /** @test */
    public function test_removes_trait_interface_abstract_from_class_names()
    {
        $testCases = [
            'UserTrait.php' => 'User',
            'ServiceInterface.php' => 'Service',
            'AbstractController.php' => 'Controller',
            'RegularClass.php' => 'RegularClass',
        ];

        foreach ($testCases as $fileName => $expectedClassName) {
            $record = [
                'message' => 'Test message',
                'context' => [],
                'level' => Logger::INFO,
                'level_name' => 'INFO',
                'channel' => 'test',
                'datetime' => new \DateTimeImmutable,
                'extra' => [
                    'source_file' => "/app/src/{$fileName}",
                ],
            ];

            $output = $this->formatter->format($record);
            $this->assertStringContainsString($expectedClassName, $output);
        }
    }

    /** @test */
    public function test_output_contains_newline_termination()
    {
        $record = [
            'message' => 'Test message',
            'context' => [],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable,
            'extra' => [],
        ];

        $output = $this->formatter->format($record);

        $this->assertStringEndsWith("\n", $output);
    }
}
