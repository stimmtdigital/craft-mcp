<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\StackFrame;

/**
 * What kind of error a log entry is, reduced to the parts that stay the same
 * each time it recurs.
 *
 * WHY so much parsing: Craft writes an exception as `(string) $exception`, so
 * class, message, throw site and trace arrive as one string. Outside dev mode
 * Craft also folds line breaks into spaces (CRAFT_LOG_ALLOW_LINE_BREAKS
 * defaults to devMode), so the trace is inline and the parser cannot split it
 * into frames; it is split here instead.
 */
final readonly class ErrorSignature {
    /** The kind of an entry that names no exception class. */
    public const string PLAIN = 'message';

    private const string CLASS_NAME = '[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+|[A-Z]\w*(?:Exception|Error)';

    private const string INLINE_FRAME = '/#(\d+) (\S+?)\((\d+)\): (.*?)(?=\s+#\d+ |$)/s';

    /**
     * @param list<StackFrame> $frames
     */
    public function __construct(
        public string $kind,
        public string $text,
        public string $normalized,
        public ?string $site,
        public array $frames,
    ) {
    }

    public static function of(Entry $entry): self {
        [$head, $trace] = self::splitTrace($entry->message);
        $frames = $entry->hasStackTrace() ? array_values((array) $entry->stackTrace) : self::inlineFrames($trace);
        [$kind, $text] = self::kind($head, $entry->category);
        [$text, $thrownAt] = self::throwSite($text);

        return new self($kind, $text, self::normalize($text), self::projectSite($thrownAt, $frames), $frames);
    }

    /**
     * What makes two entries the same error: its kind, its message with the
     * per-occurrence values taken out, and where in this project it happened.
     */
    public function key(): string {
        return $this->kind . "\0" . $this->normalized . "\0" . ($this->site ?? '');
    }

    /**
     * Mask the parts of a message that differ between occurrences of the same
     * error: quoted values, UUIDs, hex ids, the variable parts of paths, numbers.
     */
    public static function normalize(string $text): string {
        $replacements = [
            '/\'[^\']*\'/' => "'?'",
            '/"[^"]*"/' => '"?"',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '{uuid}',
            '/\b0x[0-9a-f]+\b/i' => '{hex}',
            '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b/i' => '{hex}',
            '~(?:/[^\s/:\'"]+)+/([^\s/:\'"]+)~' => '…/$1',
            '/\d+/' => '{n}',
            '/\s+/' => ' ',
        ];

        return trim((string) preg_replace(array_keys($replacements), array_values($replacements), $text));
    }

    /**
     * The log file a source name is read from, as stimmt_logs_read takes it:
     * `web-2026-01-07.log` is the web log.
     */
    public static function source(string $file): string {
        return (string) preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', basename($file, '.log'));
    }

    /**
     * @return array{0: string, 1: string} The message before "Stack trace:", and the trace text after it
     */
    private static function splitTrace(string $message): array {
        $parts = preg_split('/\s*Stack trace:\s*/', $message, 2);

        return [self::withoutContext(trim((string) ($parts[0] ?? ''))), (string) ($parts[1] ?? '')];
    }

    /**
     * Monolog appends the record's context and extra as JSON to the line; that
     * is metadata, not part of what went wrong.
     */
    private static function withoutContext(string $head): string {
        return trim((string) preg_replace('/\s+\{"[^\n]*\}\s*$/', '', $head));
    }

    /**
     * @return list<StackFrame>
     */
    private static function inlineFrames(string $trace): array {
        if (preg_match_all(self::INLINE_FRAME, $trace, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(StackFrame::fromMatch(...), $matches);
    }

    /**
     * @return array{0: string, 1: string} The exception class or "message", and the message without the class
     */
    private static function kind(string $head, string $category): array {
        $firstLine = explode("\n", $head, 2)[0];
        if (preg_match('/^\\\\?(' . self::CLASS_NAME . ')(?:\(\w+\))?: (.*)$/s', $firstLine, $match) === 1) {
            return [$match[1], $match[2]];
        }

        // Yii files an exception under its class, with ":severity" or
        // ":status" after it; take the class and leave the message whole.
        if (preg_match('/^(' . self::CLASS_NAME . ')(?::\d+)?$/', $category, $match) === 1) {
            return [$match[1], $firstLine];
        }

        return [self::PLAIN, $firstLine];
    }

    /**
     * PHP ends an exception's string with " in /path/file.php:12".
     *
     * @return array{0: string, 1: string|null} The message without it, and "file:line"
     */
    private static function throwSite(string $text): array {
        if (preg_match('/^(.*) in (\S+):(\d+)$/s', $text, $match) !== 1) {
            return [$text, null];
        }

        return [$match[1], "{$match[2]}:{$match[3]}"];
    }

    /**
     * The first place in this project's own code, which is where a fix goes:
     * the throw site when it is not a dependency, otherwise the first frame
     * that is not.
     *
     * @param list<StackFrame> $frames
     */
    private static function projectSite(?string $thrownAt, array $frames): ?string {
        $candidates = [
            ...($thrownAt === null ? [] : [$thrownAt]),
            ...array_map(static fn (StackFrame $frame): string => "{$frame->file}:{$frame->line}", $frames),
        ];

        foreach ($candidates as $candidate) {
            if (!str_contains($candidate, '/vendor/') && !str_starts_with($candidate, '[internal')) {
                return $candidate;
            }
        }

        return null;
    }
}
