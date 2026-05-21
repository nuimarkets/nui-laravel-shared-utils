<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

/**
 * Shared test helpers for Monolog 3 logging tests.
 *
 * Provides a factory for Monolog 3 LogRecord objects plus convenience
 * assertions that read context/extra/message off the record.
 */
trait LoggingTestHelpers
{
    /**
     * Monolog log level constants (Monolog 2 integer format kept here as
     * convenience inputs; internally we map to Monolog 3 Level enum values).
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
     * Create a Monolog 3 LogRecord object.
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
     * Convert a level integer (LOG_LEVEL_* constant) to the Monolog 3 Level enum.
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
     * Assert that a processed record has the expected context value.
     */
    protected function assertLogContextEquals(\Monolog\LogRecord $record, string $key, mixed $expectedValue): void
    {
        $this->assertEquals($expectedValue, $record->context[$key] ?? null);
    }

    /**
     * Assert that a processed record has the expected extra value.
     */
    protected function assertLogExtraEquals(\Monolog\LogRecord $record, string $key, mixed $expectedValue): void
    {
        $this->assertEquals($expectedValue, $record->extra[$key] ?? null);
    }

    /**
     * Get the context from a log record.
     */
    protected function getLogContext(\Monolog\LogRecord $record): array
    {
        return $record->context;
    }

    /**
     * Get the extra data from a log record.
     */
    protected function getLogExtra(\Monolog\LogRecord $record): array
    {
        return $record->extra;
    }

    /**
     * Get the message from a log record.
     */
    protected function getLogMessage(\Monolog\LogRecord $record): string
    {
        return $record->message;
    }
}
