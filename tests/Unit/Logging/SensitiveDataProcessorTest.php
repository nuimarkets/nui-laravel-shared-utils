<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Logging;

use NuiMarkets\LaravelSharedUtils\Logging\SensitiveDataProcessor;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class SensitiveDataProcessorTest extends TestCase
{
    private SensitiveDataProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processor = new SensitiveDataProcessor;
    }

    /**
     * Create a mock log record for testing
     */
    private function createLogRecord(array $context = [], array $extra = []): array
    {
        return [
            'message' => 'Test message',
            'context' => $context,
            'level' => 200, // INFO level
            'level_name' => 'INFO',
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable,
            'extra' => $extra,
        ];
    }

    public function test_redacts_authorization_header()
    {
        $record = $this->createLogRecord([
            'headers' => [
                'Authorization' => 'Bearer secret-token-12345',
                'Content-Type' => 'application/json',
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        $this->assertEquals('[REDACTED]', $processed['context']['headers']['Authorization']);
        $this->assertEquals('application/json', $processed['context']['headers']['Content-Type']);
    }

    public function test_redacts_authorization_header_with_monolog_3_log_record()
    {
        // Skip this test if Monolog 3 is not available
        if (! class_exists('\Monolog\LogRecord')) {
            $this->markTestSkipped('Monolog 3 LogRecord class not available');
        }

        // Create a LogRecord with headers containing Authorization token
        $logRecord = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'HTTP request made',
            context: [
                'headers' => [
                    'Authorization' => 'Bearer secret-token-12345',
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'TestClient/1.0',
                ],
            ],
            extra: []
        );

        $processed = $this->processor->__invoke($logRecord);

        // Assert that the returned value is a LogRecord instance
        $this->assertInstanceOf('\Monolog\LogRecord', $processed);

        // Assert that Authorization header is redacted
        $this->assertEquals('[REDACTED]', $processed->context['headers']['Authorization']);

        // Assert that other headers remain unchanged
        $this->assertEquals('application/json', $processed->context['headers']['Content-Type']);
        $this->assertEquals('TestClient/1.0', $processed->context['headers']['User-Agent']);

        // Assert that other LogRecord properties remain unchanged
        $this->assertEquals('HTTP request made', $processed->message);
        $this->assertEquals('test', $processed->channel);
        $this->assertEquals(\Monolog\Level::Info, $processed->level);
    }

    public function test_redacts_password_fields()
    {
        $record = $this->createLogRecord([
            'data' => [
                'email' => 'user@example.com',
                'password' => 'super-secret-password',
                'password_confirmation' => 'super-secret-password',
                'old_password' => 'old-secret',
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        $this->assertEquals('user@example.com', $processed['context']['data']['email']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['password']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['password_confirmation']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['old_password']);
    }

    public function test_redacts_token_and_secret_fields()
    {
        $record = $this->createLogRecord([
            'config' => [
                'api_key' => 'key-12345',
                'secret_key' => 'secret-value',
                'access_token' => 'token-abcdef',
                'auth_token' => 'auth-123',
                'regular_field' => 'not-redacted',
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        $this->assertEquals('[REDACTED]', $processed['context']['config']['secret_key']);
        $this->assertEquals('[REDACTED]', $processed['context']['config']['access_token']);
        $this->assertEquals('[REDACTED]', $processed['context']['config']['auth_token']);
        $this->assertEquals('not-redacted', $processed['context']['config']['regular_field']);

        // Note: api_key contains 'key' as substring, so it should be redacted
        $this->assertEquals('[REDACTED]', $processed['context']['config']['api_key']);
    }

    public function test_handles_nested_sensitive_data()
    {
        $record = $this->createLogRecord([
            'request' => [
                'user' => [
                    'email' => 'test@example.com',
                    'credentials' => [
                        'password' => 'nested-secret',
                        'api_token' => 'nested-token',
                    ],
                ],
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        $this->assertEquals('test@example.com', $processed['context']['request']['user']['email']);
        $this->assertEquals('[REDACTED]', $processed['context']['request']['user']['credentials']['password']);
        $this->assertEquals('[REDACTED]', $processed['context']['request']['user']['credentials']['api_token']);
    }

    public function test_preserves_non_sensitive_data()
    {
        $record = $this->createLogRecord([
            'user_id' => 123,
            'action' => 'login',
            'metadata' => [
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Mozilla/5.0',
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        $this->assertEquals(123, $processed['context']['user_id']);
        $this->assertEquals('login', $processed['context']['action']);
        $this->assertEquals('192.168.1.1', $processed['context']['metadata']['ip_address']);
        $this->assertEquals('Mozilla/5.0', $processed['context']['metadata']['user_agent']);
    }

    public function test_processes_extra_data()
    {
        $record = $this->createLogRecord(
            [],
            [
                'secret_key' => 'should-be-redacted',
                'normal_field' => 'should-remain',
            ],
        );

        $processed = $this->processor->__invoke($record);

        $this->assertEquals('[REDACTED]', $processed['extra']['secret_key']);
        $this->assertEquals('should-remain', $processed['extra']['normal_field']);
    }

    public function test_handles_empty_context_and_extra()
    {
        $record = $this->createLogRecord();

        $processed = $this->processor->__invoke($record);

        $this->assertEquals([], $processed['context']);
        $this->assertEquals([], $processed['extra']);
        $this->assertEquals('Test message', $processed['message']);
    }

    public function test_processes_monolog_3_log_record_object()
    {
        // Skip this test if Monolog 3 is not available
        if (! class_exists('\Monolog\LogRecord')) {
            $this->markTestSkipped('Monolog 3 LogRecord class not available');
        }

        // Create a real LogRecord object with sensitive data
        $logRecord = new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'test',
            level: \Monolog\Level::Info,
            message: 'Test message with sensitive data',
            context: [
                'user_id' => 123,
                'password' => 'secret-password',
                'api_token' => 'secret-token-abc123',
                'normal_field' => 'should-remain',
            ],
            extra: [
                'secret_key' => 'extra-secret',
                'authorization' => 'Bearer token-xyz789',
                'safe_field' => 'should-remain',
            ]
        );

        $processed = $this->processor->__invoke($logRecord);

        // Assert that the returned value is a LogRecord instance
        $this->assertInstanceOf('\Monolog\LogRecord', $processed);

        // Assert that sensitive data in context is redacted
        $this->assertEquals(123, $processed->context['user_id']);
        $this->assertEquals('[REDACTED]', $processed->context['password']);
        $this->assertEquals('[REDACTED]', $processed->context['api_token']);
        $this->assertEquals('should-remain', $processed->context['normal_field']);

        // Assert that sensitive data in extra is redacted
        $this->assertEquals('[REDACTED]', $processed->extra['secret_key']);
        $this->assertEquals('[REDACTED]', $processed->extra['authorization']);
        $this->assertEquals('should-remain', $processed->extra['safe_field']);

        // Assert that other properties remain unchanged
        $this->assertEquals('Test message with sensitive data', $processed->message);
        $this->assertEquals('test', $processed->channel);
        $this->assertEquals(\Monolog\Level::Info, $processed->level);
    }
}
