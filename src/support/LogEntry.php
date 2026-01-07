<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

/**
 * Immutable value object representing a parsed log entry.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class LogEntry {
    /**
     * @param string $timestamp Log timestamp (YYYY-MM-DD HH:MM:SS)
     * @param string $channel Log channel (web, console, queue, etc.)
     * @param string $level Log level (error, warning, info, debug)
     * @param string $category Log category (application, plugin name, etc.)
     * @param string $message Log message content
     * @param string $file Source log filename
     * @param StackFrame[]|null $stackTrace Parsed stack trace frames
     */
    public function __construct(
        public string $timestamp,
        public string $channel,
        public string $level,
        public string $category,
        public string $message,
        public string $file,
        public ?array $stackTrace = null,
    ) {
    }

    /**
     * Check if this entry matches a level filter.
     */
    public function matchesLevel(string $level): bool {
        return $this->level === strtolower($level);
    }

    /**
     * Check if this entry's message contains a pattern (case-insensitive).
     */
    public function matchesPattern(string $pattern): bool {
        return str_contains(strtolower($this->message), strtolower($pattern));
    }

    /**
     * Check if this entry has a stack trace.
     */
    public function hasStackTrace(): bool {
        return $this->stackTrace !== null && $this->stackTrace !== [];
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        $result = [
            'timestamp' => $this->timestamp,
            'channel' => $this->channel,
            'level' => $this->level,
            'category' => $this->category,
            'message' => $this->message,
            'file' => $this->file,
        ];

        if ($this->hasStackTrace()) {
            $result['stackTrace'] = array_map(
                static fn (StackFrame $frame): array => $frame->toArray(),
                $this->stackTrace,
            );
        }

        return $result;
    }
}
