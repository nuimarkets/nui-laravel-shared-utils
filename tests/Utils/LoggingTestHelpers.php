<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

/**
 * Shared test helpers for Monolog logging tests.
 *
 * Provides factory methods for creating Monolog 2.x and 3.x log records
 * with automatic version detection.
 */
trait LoggingTestHelpers
{
    /**
     * Monolog log level constants (Monolog 2.x format).
     */
    protected const LOG_LEVEL_DEBUG = 100;

    protected const LOG_LEVEL_INFO = 200;

    protected const LOG_LEVEL_NOTICE = 250;

    protected const LOG_LEVEL_WARNING = 300;

    protected const LOG_LEVEL_ERROR = 400;

    protected const LOG_LEVEL_CRITICAL = 500;

    protected const LOG_LEVEL_ALERT = 550;

    protected const LOG_LEVEL_EMERGENCY = 600;

    /**
     * Check if Monolog 3 is available.
     */
    protected function isMonolog3Available(): bool
    {
        return class_exists(\Monolog\LogRecord::class);
    }

    /**
     * Skip the test if Monolog 3 is not available.
     */
    protected function skipIfMonolog3NotAvailable(): void
    {
        if (! $this->isMonolog3Available()) {
            $this->markTestSkipped('Monolog 3 LogRecord class not available');
        }
    }

    /**
     * Skip the test if Monolog 2 format is not available (i.e., Monolog 3 is the only option).
     */
    protected function skipIfMonolog2NotAvailable(): void
    {
        if ($this->isMonolog3Available()) {
            $this->markTestSkipped('Monolog 2 array format not available');
        }
    }

    /**
     * Create a Monolog 2.x array-based log record (simple signature for compatibility).
     *
     * This method provides a simple interface matching the common test pattern where
     * only context and extra arrays are needed.
     *
     * @param  array  $context  The log context
     * @param  array  $extra  The extra data
     * @param  string  $message  The log message
     * @param  int  $level  The log level (use LOG_LEVEL_* constants)
     * @param  string  $channel  The log channel
     */
    protected function createLogRecord(
        array $context = [],
        array $extra = [],
        string $message = 'Test message',
        int $level = self::LOG_LEVEL_INFO,
        string $channel = 'test'
    ): array {
        return $this->createMonolog2Record($message, $level, $context, $extra, $channel);
    }

    /**
     * Create a log record compatible with the current Monolog version.
     *
     * This method auto-detects the Monolog version and returns the appropriate format.
     *
     * @param  string  $message  The log message
     * @param  int  $level  The log level (use LOG_LEVEL_* constants)
     * @param  array  $context  The log context
     * @param  array  $extra  The extra data
     * @param  string  $channel  The log channel
     * @return array|\Monolog\LogRecord Returns array for Monolog 2.x, LogRecord for Monolog 3.x
     */
    protected function createVersionAwareLogRecord(
        string $message = 'Test message',
        int $level = self::LOG_LEVEL_INFO,
        array $context = [],
        array $extra = [],
        string $channel = 'test'
    ): array|\Monolog\LogRecord {
        if ($this->isMonolog3Available()) {
            return $this->createMonolog3Record($message, $level, $context, $extra, $channel);
        }

        return $this->createMonolog2Record($message, $level, $context, $extra, $channel);
    }

    /**
     * Create a Monolog 2.x array-based log record.
     *
     * @param  string  $message  The log message
     * @param  int  $level  The log level (use LOG_LEVEL_* constants)
     * @param  array  $context  The log context
     * @param  array  $extra  The extra data
     * @param  string  $channel  The log channel
     */
    protected function createMonolog2Record(
        string $message = 'Test message',
        int $level = self::LOG_LEVEL_INFO,
        array $context = [],
        array $extra = [],
        string $channel = 'test'
    ): array {
        return [
            'message' => $message,
            'context' => $context,
            'level' => $level,
            'level_name' => $this->getLevelName($level),
            'channel' => $channel,
            'datetime' => new \DateTimeImmutable,
            'extra' => $extra,
        ];
    }

    /**
     * Create a Monolog 3.x LogRecord object.
     *
     * @param  string  $message  The log message
     * @param  int  $level  The log level (use LOG_LEVEL_* constants)
     * @param  array  $context  The log context
     * @param  array  $extra  The extra data
     * @param  string  $channel  The log channel
     */
    protected function createMonolog3Record(
        string $message = 'Test message',
        int $level = self::LOG_LEVEL_INFO,
        array $context = [],
        array $extra = [],
        string $channel = 'test'
    ): \Monolog\LogRecord {
        return new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable,
            channel: $channel,
            level: $this->getMonolog3Level($level),
            message: $message,
            context: $context,
            extra: $extra
        );
    }

    /**
     * Get the level name for a Monolog 2.x log level.
     */
    protected function getLevelName(int $level): string
    {
        return match ($level) {
            self::LOG_LEVEL_DEBUG => 'DEBUG',
            self::LOG_LEVEL_INFO => 'INFO',
            self::LOG_LEVEL_NOTICE => 'NOTICE',
            self::LOG_LEVEL_WARNING => 'WARNING',
            self::LOG_LEVEL_ERROR => 'ERROR',
            self::LOG_LEVEL_CRITICAL => 'CRITICAL',
            self::LOG_LEVEL_ALERT => 'ALERT',
            self::LOG_LEVEL_EMERGENCY => 'EMERGENCY',
            default => 'INFO',
        };
    }

    /**
     * Convert a Monolog 2.x level integer to a Monolog 3.x Level enum.
     */
    protected function getMonolog3Level(int $level): \Monolog\Level
    {
        return match ($level) {
            self::LOG_LEVEL_DEBUG => \Monolog\Level::Debug,
            self::LOG_LEVEL_INFO => \Monolog\Level::Info,
            self::LOG_LEVEL_NOTICE => \Monolog\Level::Notice,
            self::LOG_LEVEL_WARNING => \Monolog\Level::Warning,
            self::LOG_LEVEL_ERROR => \Monolog\Level::Error,
            self::LOG_LEVEL_CRITICAL => \Monolog\Level::Critical,
            self::LOG_LEVEL_ALERT => \Monolog\Level::Alert,
            self::LOG_LEVEL_EMERGENCY => \Monolog\Level::Emergency,
            default => \Monolog\Level::Info,
        };
    }

    /**
     * Assert that a processed record has the expected structure.
     *
     * Works with both Monolog 2.x arrays and Monolog 3.x LogRecord objects.
     *
     * @param  array|\Monolog\LogRecord  $record  The processed record
     * @param  string  $key  The key to check in context
     * @param  mixed  $expectedValue  The expected value
     */
    protected function assertLogContextEquals($record, string $key, mixed $expectedValue): void
    {
        if ($record instanceof \Monolog\LogRecord) {
            $this->assertEquals($expectedValue, $record->context[$key] ?? null);
        } else {
            $this->assertEquals($expectedValue, $record['context'][$key] ?? null);
        }
    }

    /**
     * Assert that a processed record has the expected extra value.
     *
     * Works with both Monolog 2.x arrays and Monolog 3.x LogRecord objects.
     *
     * @param  array|\Monolog\LogRecord  $record  The processed record
     * @param  string  $key  The key to check in extra
     * @param  mixed  $expectedValue  The expected value
     */
    protected function assertLogExtraEquals($record, string $key, mixed $expectedValue): void
    {
        if ($record instanceof \Monolog\LogRecord) {
            $this->assertEquals($expectedValue, $record->extra[$key] ?? null);
        } else {
            $this->assertEquals($expectedValue, $record['extra'][$key] ?? null);
        }
    }

    /**
     * Get the context from a log record (works with both Monolog versions).
     *
     * @param  array|\Monolog\LogRecord  $record
     */
    protected function getLogContext($record): array
    {
        if ($record instanceof \Monolog\LogRecord) {
            return $record->context;
        }

        return $record['context'] ?? [];
    }

    /**
     * Get the extra data from a log record (works with both Monolog versions).
     *
     * @param  array|\Monolog\LogRecord  $record
     */
    protected function getLogExtra($record): array
    {
        if ($record instanceof \Monolog\LogRecord) {
            return $record->extra;
        }

        return $record['extra'] ?? [];
    }

    /**
     * Get the message from a log record (works with both Monolog versions).
     *
     * @param  array|\Monolog\LogRecord  $record
     */
    protected function getLogMessage($record): string
    {
        if ($record instanceof \Monolog\LogRecord) {
            return $record->message;
        }

        return $record['message'] ?? '';
    }
}
