<?php

namespace Nuimarkets\LaravelSharedUtils\Logging;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use JsonSerializable;
use Monolog\Logger;

/**
 * Formats log records as colored JSON lines with improved readability.
 */
class ColoredJsonLineFormatter extends ColoredLineFormatter
{
    private const HEADER_FORMAT = "%-32s %-10s %s\n";

    private const MAX_LINE_LENGTH = 155;

    private const INDENT_SIZE = 4;

    private const KEY_PADDING = 20;

    public function format(array $record): string
    {
        $colorScheme = $this->getColorScheme();
        $className = $this->extractClassName($record);

        // Format and colorize header
        $headerLine = sprintf(
            self::HEADER_FORMAT,
            $className,
            $record['level_name'],
            $record['message'],
        );

        $output = $this->colorize($headerLine, $record['level'], $colorScheme);

        // Add context if present
        if (! empty($record['context'])) {
            $output .= $this->formatContext($record['context'], $colorScheme);
        }

        if (! empty($record['extra']) && env('LOG_PRETTY_SHOW_EXTRA', false)) {
            $output .= $this->formatContext($record['extra'], $colorScheme);
        }

        return $output."\n";
    }

    private function extractClassName(array $record): string
    {
        if (! isset($record['extra']['source_file'])) {
            return '';
        }

        $path = $record['extra']['source_file'];
        $className = basename($path, '.php');

        return str_replace(['Trait', 'Interface', 'Abstract'], '', $className);
    }

    private function formatContext(array $context, object $colorScheme): string
    {
        $debugColor = $colorScheme->getColorizeString(Logger::WARNING);
        $reset = $colorScheme->getResetString();

        $dataTree = $this->formatDataAsTree($context, 1);
        $lines = explode("\n", $dataTree);

        $coloredLines = array_map(
            function ($line) use ($debugColor, $reset) {
                $truncated = strlen($line) > self::MAX_LINE_LENGTH;
                $truncatedLine = substr($line, 0, self::MAX_LINE_LENGTH - ($truncated ? 3 : 0));

                return $debugColor.$truncatedLine.($truncated ? '...' : '').$reset;
            },
            $lines,
        );

        return implode("\n", $coloredLines)."\n";
    }

    private function colorize(string $text, int $level, object $colorScheme): string
    {
        return $colorScheme->getColorizeString($level).
            $text.
            $colorScheme->getResetString();
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
