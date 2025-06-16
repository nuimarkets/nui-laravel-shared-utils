<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Logging;

use Nuimarkets\LaravelSharedUtils\Logging\ColoredJsonLineFormatter;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;
use Monolog\Logger;

class ColoredJsonLineFormatterTest extends TestCase
{
    private ColoredJsonLineFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ColoredJsonLineFormatter();
    }

    /** @test */
    public function test_formats_monolog_2_array_record_correctly()
    {
        // Monolog 2.x array format
        $record = [
            'message' => 'Test message',
            'context' => ['key' => 'value'],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
            'extra' => [
                'source_file' => '/path/to/TestClass.php'
            ],
        ];

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
        if (!class_exists('Monolog\LogRecord')) {
            $this->markTestSkipped('Monolog 3 LogRecord class not available');
        }

        // Monolog 3.x LogRecord format
        $record = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'Test message',
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
        $record = [
            'message' => 'Test message',
            'context' => [],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
            'extra' => [],
        ];

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString('INFO', $output);
        // Should not contain context formatting
        $this->assertStringNotContainsString('key', $output);
    }

    /** @test */
    public function test_extracts_class_name_from_source_file()
    {
        $record = [
            'message' => 'Test message',
            'context' => [],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
            'extra' => [
                'source_file' => '/app/src/Services/UserServiceTrait.php'
            ],
        ];

        $output = $this->formatter->format($record);

        // Should extract 'UserService' (removing 'Trait' suffix)
        $this->assertStringContainsString('UserService', $output);
        $this->assertStringNotContainsString('UserServiceTrait', $output);
    }

    /** @test */
    public function test_handles_missing_source_file()
    {
        $record = [
            'message' => 'Test message',
            'context' => [],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
            'extra' => [],
        ];

        $output = $this->formatter->format($record);

        $this->assertStringContainsString('Test message', $output);
        $this->assertStringContainsString('INFO', $output);
        // Should handle gracefully without errors
        $this->assertIsString($output);
    }

    /** @test */
    public function test_formats_nested_context_data()
    {
        $record = [
            'message' => 'Test message',
            'context' => [
                'user' => [
                    'id' => 123,
                    'name' => 'John Doe'
                ],
                'metadata' => [
                    'action' => 'login',
                    'ip' => '192.168.1.1'
                ]
            ],
            'level' => Logger::INFO,
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
            'extra' => [],
        ];

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
            ['level' => Logger::DEBUG, 'name' => 'DEBUG'],
            ['level' => Logger::INFO, 'name' => 'INFO'],
            ['level' => Logger::WARNING, 'name' => 'WARNING'],
            ['level' => Logger::ERROR, 'name' => 'ERROR'],
            ['level' => Logger::CRITICAL, 'name' => 'CRITICAL'],
        ];

        foreach ($levels as $levelData) {
            $record = [
                'message' => 'Test message',
                'context' => [],
                'level' => $levelData['level'],
                'level_name' => $levelData['name'],
                'channel' => 'test',
                'datetime' => new \DateTimeImmutable(),
                'extra' => [],
            ];

            $output = $this->formatter->format($record);

            $this->assertStringContainsString($levelData['name'], $output);
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
                'datetime' => new \DateTimeImmutable(),
                'extra' => [
                    'source_file' => "/app/src/{$fileName}"
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
            'datetime' => new \DateTimeImmutable(),
            'extra' => [],
        ];

        $output = $this->formatter->format($record);

        $this->assertStringEndsWith("\n", $output);
    }
}