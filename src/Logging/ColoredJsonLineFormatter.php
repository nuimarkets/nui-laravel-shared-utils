<?php

namespace Nuimarkets\LaravelSharedUtils\Logging;

use JsonSerializable;
use Monolog\Formatter\FormatterInterface;
use Monolog\Logger;

/**
 * Formats log records as colored JSON lines with improved readability.
 * Compatible with both Monolog 2.x and 3.x.
 */
class ColoredJsonLineFormatter implements FormatterInterface
{
    private const HEADER_FORMAT = "%-32s %-10s %s\n";

    private const MAX_LINE_LENGTH = 155;

    private const INDENT_SIZE = 4;

    private const KEY_PADDING = 20;

    // ANSI color codes for different log levels
    private const COLOR_SCHEME = [
        Logger::DEBUG => "\033[0;37m",     // Light gray
        Logger::INFO => "\033[0;32m",      // Green
        Logger::NOTICE => "\033[0;36m",    // Cyan
        Logger::WARNING => "\033[0;33m",   // Yellow
        Logger::ERROR => "\033[0;31m",     // Red
        Logger::CRITICAL => "\033[1;31m",  // Bold red
        Logger::ALERT => "\033[1;35m",     // Bold magenta
        Logger::EMERGENCY => "\033[1;31m", // Bold red
    ];

    private const COLOR_RESET = "\033[0m";

    /**
     * Whether colors are enabled for this formatter
     */
    private bool $colorsEnabled;

    public function __construct(bool $colorsEnabled = true)
    {
        $this->colorsEnabled = $colorsEnabled;
    }

    public function format($record): string
    {
        // Handle both Monolog 2.x (array) and 3.x (LogRecord) formats
        $isLogRecord = class_exists('Monolog\LogRecord') && $record instanceof \Monolog\LogRecord;

        $className = $this->extractClassName($record, $isLogRecord);

        // Extract data based on record type
        $levelName = $isLogRecord ? $record->level->getName() : $record['level_name'];
        $message = $isLogRecord ? $record->message : $record['message'];
        $level = $isLogRecord ? $record->level->value : $record['level'];
        $context = $isLogRecord ? $record->context : $record['context'];
        $extra = $isLogRecord ? $record->extra : $record['extra'];

        // Format and colorize header
        $headerLine = sprintf(
            self::HEADER_FORMAT,
            $className,
            $levelName,
            $message,
        );

        $output = $this->colorize($headerLine, $level);

        // Add context if present
        if (! empty($context)) {
            $output .= $this->formatContext($context);
        }

        if (! empty($extra) && env('LOG_PRETTY_SHOW_EXTRA', false)) {
            $output .= $this->formatContext($extra);
        }

        return $output."\n";
    }

    public function formatBatch(array $records): string
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }

        return $message;
    }

    private function extractClassName($record, ?bool $isLogRecord = null): string
    {
        if ($isLogRecord === null) {
            $isLogRecord = class_exists('Monolog\LogRecord') && $record instanceof \Monolog\LogRecord;
        }

        $extra = $isLogRecord ? $record->extra : $record['extra'];

        if (! isset($extra['source_file'])) {
            return '';
        }

        $path = $extra['source_file'];
        $className = basename($path, '.php');

        return str_replace(['Trait', 'Interface', 'Abstract'], '', $className);
    }

    private function formatContext(array $context): string
    {
        $debugColor = $this->getColorForLevel(Logger::WARNING);
        $reset = self::COLOR_RESET;

        $dataTree = $this->formatDataAsTree($context, 1);
        $lines = explode("\n", $dataTree);

        $coloredLines = array_map(
            function ($line) use ($debugColor, $reset) {
                $truncated = strlen($line) > self::MAX_LINE_LENGTH;
                $truncatedLine = substr($line, 0, self::MAX_LINE_LENGTH - ($truncated ? 3 : 0));

                return $this->colorsEnabled
                    ? $debugColor.$truncatedLine.($truncated ? '...' : '').$reset
                    : $truncatedLine.($truncated ? '...' : '');
            },
            $lines,
        );

        return implode("\n", $coloredLines)."\n";
    }

    private function colorize(string $text, int $level): string
    {
        if (! $this->colorsEnabled) {
            return $text;
        }

        return $this->getColorForLevel($level).
            $text.
            self::COLOR_RESET;
    }

    private function getColorForLevel(int $level): string
    {
        return self::COLOR_SCHEME[$level] ?? self::COLOR_SCHEME[Logger::INFO];
    }

    protected function formatDataAsTree(mixed $data, int $indent = 0): string
    {
        if (! is_array($data)) {
            return $this->formatValue($data);
        }

        $result = '';
        $prefix = $this->getIndentation($indent);

        foreach ($data as $key => $value) {
            $formattedKey = is_string($key) ? $key : "[$key]";
            $result .= $prefix.$formattedKey;

            if (is_array($value)) {
                $result .= PHP_EOL.$this->formatDataAsTree($value, $indent + 1);
            } else {
                $padding = max(1, self::KEY_PADDING - strlen($formattedKey));
                $result .= str_repeat(' ', $padding).
                    $this->formatValue($value).
                    PHP_EOL;
            }
        }

        return $result;
    }

    private function getIndentation(int $level): string
    {
        return str_repeat(' ', $level * self::INDENT_SIZE);
    }

    private function formatArray(array $value): string
    {
        return '['.implode(', ', array_map([$this, 'formatValue'], $value)).']';
    }

    /**
     * Format value
     *
     * Monolog's JsonFormatter::normalize() behavior for objects should be noted here esp
     * - JsonSerializable
     * - __toString
     *
     * Logging objects is best avoided so avoid encouraging it by logging only the Object name
     *
     * @param  mixed  $value  The value to format
     * @return string The formatted string representation
     */
    protected function formatValue(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => $this->formatArray($value),
            $value instanceof JsonSerializable => json_encode($value),
            is_object($value) => 'Object('.get_class($value).')',
            default => '[Unknown Type]',
        };
    }
}
