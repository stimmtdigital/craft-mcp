<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

/**
 * The repeated queries in one request, read off the debug module's db panel.
 *
 * WHY the SQL is normalized before it is grouped or shown: the debug module
 * logs Yii's raw SQL, with every bound value written into it (password hashes,
 * session and token values, email addresses). Replacing literals with ? is
 * what keeps those out of a tool result, and it is also what makes the
 * grouping useful: an N+1 is the same statement with a different id each
 * time, which an exact-text comparison would count as N different queries.
 */
final class QueryDuplicates {
    /** Yii\log\Logger::LEVEL_PROFILE_BEGIN: each query is logged as a begin and an end entry. */
    public const int PROFILE_BEGIN = 0x50;

    public const int SQL_LENGTH = 300;

    public const int MAX_GROUPS = 5;

    public static function normalize(string $sql): string {
        $patterns = [
            // Single-quoted strings, with '' and \' escapes inside them.
            "/'(?:[^'\\\\]|\\\\.|'')*'/s" => '?',
            '/\b0x[0-9a-f]+\b/i' => '?',
            // Numbers standing alone, not the digits inside an identifier (t1, elements_sites2).
            '/(?<![\w$.])-?\d+(?:\.\d+)?(?![\w.])/' => '?',
            '/\s+/' => ' ',
        ];
        $normalized = (string) preg_replace(array_keys($patterns), array_values($patterns), $sql);

        // A list of placeholders is one shape however long the list was.
        $normalized = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?, ...)', $normalized);

        return mb_strimwidth(trim($normalized), 0, self::SQL_LENGTH, '…');
    }

    /**
     * The statements this request ran more than once, most repeated first.
     *
     * @param array<mixed> $messages The db panel's `messages`: Yii log entries [message, level, category, ...]
     * @return list<array{sql: string, count: int}>
     */
    public static function group(array $messages, int $max = self::MAX_GROUPS): array {
        $counts = array_count_values(array_map(self::normalize(...), self::statements($messages)));
        $repeated = array_filter($counts, static fn (int $count): bool => $count > 1);
        arsort($repeated);

        $groups = [];
        foreach (array_slice($repeated, 0, $max, true) as $sql => $count) {
            $groups[] = ['sql' => (string) $sql, 'count' => $count];
        }

        return $groups;
    }

    /**
     * Only the begin entries: counting both halves would report every query
     * as a duplicate of itself.
     *
     * @param array<mixed> $messages
     * @return list<string>
     */
    private static function statements(array $messages): array {
        $statements = [];
        foreach ($messages as $message) {
            if (is_array($message) && ($message[1] ?? null) === self::PROFILE_BEGIN && is_string($message[0] ?? null)) {
                $statements[] = $message[0];
            }
        }

        return $statements;
    }
}
