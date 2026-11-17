<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use Craft;
use yii\db\Expression;

/**
 * A LIKE prefilter on `elements_sites.content` that works on both drivers.
 *
 * WHY a prefilter at all: decoding every row's JSON in PHP to find the few
 * holding a ref tag is the expensive part of a scan; the database can drop
 * the rest first. The parser still decides what is a tag, so a false positive
 * here costs a decode, never a wrong answer.
 *
 * WHY the cast on PostgreSQL: Craft creates the column as `jsonb` there, and
 * jsonb has no LIKE operator. MySQL compares a JSON column as text as it is.
 */
final class ContentFilter {
    /**
     * Rows whose content mentions a tag for any of $handles (`{entry:` ...).
     * The values are bound parameters; the query builder escapes `%` and `_`.
     *
     * @param list<string> $handles
     * @return array<int, mixed>
     */
    public static function mentioning(string $column, array $handles): array {
        $target = Craft::$app->getDb()->getDriverName() === 'pgsql'
            ? new Expression("CAST([[{$column}]] AS TEXT)")
            : $column;

        return ['or', ...array_map(
            static fn (string $handle): array => ['like', $target, '{' . $handle . ':'],
            $handles,
        )];
    }
}
