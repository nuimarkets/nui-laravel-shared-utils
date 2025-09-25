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

        $this->assertEquals('[REDACTED]', $processed['context']['data']['email']); // PII redacted by default
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

        $this->assertEquals('[REDACTED]', $processed['context']['request']['user']['email']); // PII redacted by default
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
                'feature_flag' => 'dark_mode_enabled',
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        $this->assertEquals('[REDACTED]', $processed['context']['user_id']); // PII redacted by default
        $this->assertEquals('login', $processed['context']['action']); // Non-sensitive preserved
        $this->assertEquals('[REDACTED]', $processed['context']['metadata']['ip_address']); // PII redacted by default
        $this->assertEquals('Mozilla/5.0', $processed['context']['metadata']['user_agent']); // Non-sensitive preserved
        $this->assertEquals('dark_mode_enabled', $processed['context']['metadata']['feature_flag']); // Non-sensitive preserved
    }

    public function test_configurable_pii_redaction_enabled_by_default()
    {
        $record = $this->createLogRecord([
            'user_data' => [
                'email' => 'user@example.com',
                'password' => 'secret-password',
                'phone' => '+1234567890',
                'address' => '123 Main St',
            ],
        ]);

        $processed = $this->processor->__invoke($record);

        // With PII redaction enabled by default, both auth and PII fields are redacted
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['email']);
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['phone']);
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['address']);
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['password']);
    }

    public function test_configurable_pii_redaction_enabled()
    {
        $processor = new SensitiveDataProcessor([], true); // Enable PII redaction

        $record = $this->createLogRecord([
            'user_data' => [
                'email' => 'user@example.com',
                'password' => 'secret-password',
                'phone' => '+1234567890',
                'address' => '123 Main St',
            ],
        ]);

        $processed = $processor->__invoke($record);

        // With PII redaction enabled, both auth and PII fields are redacted
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['email']);
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['phone']);
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['address']);
        $this->assertEquals('[REDACTED]', $processed['context']['user_data']['password']);
    }

    public function test_preserve_fields_override()
    {
        $processor = new SensitiveDataProcessor(['user_email', 'ip_address'], true); // Preserve exact fields, enable PII redaction

        $record = $this->createLogRecord([
            'debug_info' => [
                'user_email' => 'debug@example.com', // Should be preserved
                'ip_address' => '192.168.1.1', // Should be preserved
                'phone' => '+1234567890', // Should be redacted (PII enabled, not preserved)
                'password' => 'secret', // Should be redacted (auth field)
            ],
        ]);

        $processed = $processor->__invoke($record);

        // Preserved fields should remain visible
        $this->assertEquals('debug@example.com', $processed['context']['debug_info']['user_email']);
        $this->assertEquals('192.168.1.1', $processed['context']['debug_info']['ip_address']);

        // Non-preserved fields should be redacted
        $this->assertEquals('[REDACTED]', $processed['context']['debug_info']['phone']);
        $this->assertEquals('[REDACTED]', $processed['context']['debug_info']['password']);
    }

    public function test_fluent_configuration_methods()
    {
        $processor = (new SensitiveDataProcessor)
            ->enablePiiRedaction(true)
            ->preserveFields(['user_email']);

        $record = $this->createLogRecord([
            'data' => [
                'user_email' => 'preserved@example.com',
                'phone' => '+1234567890',
                'password' => 'secret',
            ],
        ]);

        $processed = $processor->__invoke($record);

        // Email should be preserved, phone and password redacted
        $this->assertEquals('preserved@example.com', $processed['context']['data']['user_email']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['phone']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['password']);
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
        $this->assertEquals('[REDACTED]', $processed->context['user_id']); // PII redacted by default
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

    public function test_preserve_fields_exact_match_only()
    {
        $processor = (new SensitiveDataProcessor)
            ->enablePiiRedaction(true)
            ->preserveFields(['ip_address']); // Exact field name only

        $record = $this->createLogRecord([
            'data' => [
                'zip_code' => '12345', // Should be redacted (PII field)
                'ship_to' => 'Alice',  // Should not be redacted (not PII)
                'ip_address' => '192.168.0.1', // Should be preserved (exact match)
                'client_ip' => '10.0.0.1', // Should be redacted (PII but not preserved)
                'user_email' => 'test@example.com', // Should be redacted (PII, not preserved)
            ],
        ]);

        $processed = $processor->__invoke($record);

        // PII fields should be redacted unless exactly preserved
        $this->assertEquals('[REDACTED]', $processed['context']['data']['zip_code']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['client_ip']);
        $this->assertEquals('[REDACTED]', $processed['context']['data']['user_email']);

        // Non-PII fields should remain untouched
        $this->assertEquals('Alice', $processed['context']['data']['ship_to']);

        // Exactly preserved fields should remain visible
        $this->assertEquals('192.168.0.1', $processed['context']['data']['ip_address']);
    }

    public function test_authorization_header_case_variants()
    {
        $processor = new SensitiveDataProcessor;

        $record = $this->createLogRecord([
            'headers' => [
                'Authorization' => 'Bearer token123',
                'AUTHORIZATION' => 'Bearer token456',
                'authorization' => 'Bearer token789',
            ],
        ]);

        $processed = $processor->__invoke($record);

        // All authorization header variants should be redacted
        $this->assertEquals('[REDACTED]', $processed['context']['headers']['Authorization']);
        $this->assertEquals('[REDACTED]', $processed['context']['headers']['AUTHORIZATION']);
        $this->assertEquals('[REDACTED]', $processed['context']['headers']['authorization']);
    }
}
