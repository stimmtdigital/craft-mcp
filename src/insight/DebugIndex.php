<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The Yii debug module's index.data, read into request summaries.
 *
 * Each index entry is the summary yii\debug\LogTarget::collectSummary() wrote
 * at the end of a request, with the profiling panel's peakMemory and
 * processingTime added by export(). Only timing, counts and the route leave
 * here: the entry also holds the client IP, and the data files beside it hold
 * the request's params, cookies and headers, none of which a caller needs to
 * find a slow page and all of which can carry a session id or a secret.
 */
final class DebugIndex {
    /** yii\debug\LogTarget names the request method COMMAND for a console run. */
    private const string CONSOLE = 'COMMAND';

    /**
     * Requests at or above $minMs, slowest first. A request with no recorded
     * duration (its profiling panel failed) sorts last and never passes a
     * minimum, because its speed is unknown rather than zero.
     *
     * @param array<mixed> $manifest Tag => summary, as unserialized from index.data
     * @return list<array{tag: string, url: string, method: string|null, statusCode: int|null, durationMs: float|null, memoryMb: float|null, queryCount: int|null, time: string|null}>
     */
    public static function summaries(array $manifest, ?float $minMs = null): array {
        $summaries = [];
        foreach ($manifest as $tag => $entry) {
            if (is_array($entry) && self::isTag((string) $tag)) {
                $summaries[] = self::summary((string) $tag, $entry);
            }
        }

        if ($minMs !== null) {
            $summaries = array_values(array_filter(
                $summaries,
                static fn (array $summary): bool => $summary['durationMs'] !== null && $summary['durationMs'] >= $minMs,
            ));
        }

        $duration = static fn (array $summary): float => $summary['durationMs'] ?? -1.0;
        usort($summaries, static fn (array $a, array $b): int => $duration($b) <=> $duration($a));

        return $summaries;
    }

    /**
     * Tags are uniqid() values. Anything else in the index is not a request
     * this module wrote, and a tag becomes a file name, so it is checked
     * before it can become a path.
     */
    public static function isTag(string $tag): bool {
        return preg_match('/^[A-Za-z0-9]+$/', $tag) === 1;
    }

    /**
     * @param array<mixed> $entry
     * @return array{tag: string, url: string, method: string|null, statusCode: int|null, durationMs: float|null, memoryMb: float|null, queryCount: int|null, time: string|null}
     */
    public static function summary(string $tag, array $entry): array {
        $method = is_string($entry['method'] ?? null) ? $entry['method'] : null;

        return [
            'tag' => $tag,
            'url' => self::safeUrl(is_string($entry['url'] ?? null) ? $entry['url'] : '', $method),
            'method' => $method,
            'statusCode' => is_numeric($entry['statusCode'] ?? null) ? (int) $entry['statusCode'] : null,
            'durationMs' => self::scaled($entry['processingTime'] ?? null, 1000),
            'memoryMb' => self::scaled($entry['peakMemory'] ?? null, 1 / 1048576),
            'queryCount' => is_numeric($entry['sqlCount'] ?? null) ? (int) $entry['sqlCount'] : null,
            'time' => self::time($entry['time'] ?? null),
        ];
    }

    /**
     * The URL without its query string, fragment or credentials: a query
     * string carries tokens (password resets, preview tokens, verification
     * codes). A console run keeps only its route, because its arguments can
     * be a password passed on the command line.
     */
    public static function safeUrl(string $url, ?string $method): string {
        if ($method === self::CONSOLE) {
            $words = preg_split('/\s+/', trim($url)) ?: [];

            return implode(' ', array_slice($words, 0, 3));
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $origin = isset($parts['host']) ? ($parts['scheme'] ?? 'http') . '://' . $parts['host'] : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $origin . $port . ($parts['path'] ?? '/');
    }

    /**
     * Seconds to milliseconds, bytes to megabytes, to one decimal. Null when
     * the module recorded nothing, which is not the same as zero.
     */
    private static function scaled(mixed $value, float $factor): ?float {
        return is_numeric($value) ? round((float) $value * $factor, 1) : null;
    }

    private static function time(mixed $value): ?string {
        if (!is_numeric($value)) {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', (float) $value));

        return $moment === false ? null : $moment->format(DateTimeInterface::ATOM);
    }
}
