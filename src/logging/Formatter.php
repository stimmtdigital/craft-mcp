<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\logging;

use Mcp\Schema\Content\TextContent;
use stimmt\craft\Mcp\text\Palette;

/**
 * Formats log entries as human-readable text.
 *
 * Log lines are the one payload the shared Renderer cannot improve on: the
 * level colouring, the timestamp/category/message ordering and the indented
 * stack trace are what make a log readable, and a table of them would not be.
 * So this stays its own formatter, and only borrows the Palette so that
 * whether it colours is decided in the same single place as everything else.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Formatter {
    public function __construct(private Palette $palette) {
    }

    /**
     * Format log entries as text.
     *
     * @param Entry[] $entries
     */
    public function format(array $entries): TextContent {
        if ($entries === []) {
            return new TextContent('No log entries found.');
        }

        $lines = array_map($this->formatEntry(...), $entries);

        return new TextContent(implode("\n\n", $lines));
    }

    /**
     * Format a single log entry.
     */
    private function formatEntry(Entry $entry): string {
        $level = strtoupper($entry->level);
        $levelFormatted = $this->colorizeLevel($level, $entry->level);

        $line = sprintf(
            '%s [%s] %s: %s',
            $this->palette->muted($entry->timestamp),
            $levelFormatted,
            $this->palette->subtle($entry->category),
            $entry->message,
        );

        if ($entry->hasStackTrace()) {
            $line .= $this->formatStackTrace($entry->stackTrace);
        }

        return $line;
    }

    /**
     * Colorize log level based on severity.
     */
    private function colorizeLevel(string $display, string $level): string {
        return match (strtolower($level)) {
            'error' => $this->palette->error($display),
            'warning' => $this->palette->warning($display),
            default => $this->palette->muted($display),
        };
    }

    /**
     * Format stack trace frames.
     *
     * @param StackFrame[] $frames
     */
    private function formatStackTrace(array $frames): string {
        $lines = array_map(
            fn (StackFrame $frame): string => $this->palette->muted(sprintf(
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
