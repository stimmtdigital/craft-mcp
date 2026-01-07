<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Schema\Content\TextContent;

/**
 * Formats log entries as human-readable colored text.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class LogFormatter {
    /**
     * Format log entries as colored text.
     *
     * @param LogEntry[] $entries
     */
    public static function format(array $entries): TextContent {
        if ($entries === []) {
            return new TextContent('No log entries found.');
        }

        $lines = array_map(self::formatEntry(...), $entries);

        return new TextContent(implode("\n\n", $lines));
    }

    /**
     * Format a single log entry with ANSI colors.
     */
    private static function formatEntry(LogEntry $entry): string {
        $level = strtoupper($entry->level);
        $levelFormatted = self::colorizeLevel($level, $entry->level);

        $line = sprintf(
            '%s [%s] %s: %s',
            Ansi::dim($entry->timestamp),
            $levelFormatted,
            Ansi::gray($entry->category),
            $entry->message,
        );

        if ($entry->hasStackTrace()) {
            $line .= self::formatStackTrace($entry->stackTrace);
        }

        return $line;
    }

    /**
     * Colorize log level based on severity.
     */
    private static function colorizeLevel(string $display, string $level): string {
        return match (strtolower($level)) {
            'error' => Ansi::red($display),
            'warning' => Ansi::yellow($display),
            default => Ansi::dim($display),
        };
    }

    /**
     * Format stack trace frames.
     *
     * @param StackFrame[] $frames
     */
    private static function formatStackTrace(array $frames): string {
        $lines = array_map(
            static fn (StackFrame $frame): string => Ansi::dim(sprintf(
                '  #%d %s(%d): %s',
                $frame->index,
                $frame->file,
                $frame->line,
                $frame->call,
            )),
            $frames,
        );

        return "\n" . implode("\n", $lines);
    }
}
