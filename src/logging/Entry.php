<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\logging;

/**
 * Immutable value object representing a parsed log entry.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Entry {
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
     *
     * WHY the scope is a choice: a message carries any continuation lines the
     * log wrote under it, so a request-context dump hundreds of lines long is
     * part of it. Searching all of that is the point when an agent hunts for a
     * class or a URL, and wrong when the question is what kind of entry this
     * is, which only the logged line itself answers.
     */
    public function matchesPattern(string $pattern, bool $headlineOnly = false): bool {
        $haystack = $headlineOnly ? $this->headline() : $this->message;

        return str_contains(strtolower($haystack), strtolower($pattern));
    }

    /**
     * The line the log wrote, without the continuation lines appended to it.
     */
    public function headline(): string {
        $break = strpos($this->message, "\n");

        return $break === false ? $this->message : substr($this->message, 0, $break);
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
