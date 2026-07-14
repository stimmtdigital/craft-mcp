<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\query;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/**
 * groupBy counting for count_entries: attribute buckets, date buckets
 * (granularity:dateAttribute), or field-value buckets. Iterates the query in
 * batches server-side so the client only ever sees the counts.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Buckets {
    private const array ATTRIBUTES = ['status', 'type', 'section', 'site', 'author'];

    private const array DATE_ATTRIBUTES = ['dateCreated', 'dateUpdated', 'postDate'];

    private const array GRANULARITIES = ['day', 'week', 'month', 'year'];

    private const int MAX_BUCKETS = 200;

    private const string EMPTY_KEY = '(empty)';

    /**
     * @return array{kind: string, target: string, granularity: ?string}
     */
    public static function parseGroupBy(string $groupBy): array {
        if (in_array($groupBy, self::ATTRIBUTES, true)) {
            return ['kind' => 'attribute', 'target' => $groupBy, 'granularity' => null];
        }

        if (str_contains($groupBy, ':')) {
            [$granularity, $target] = explode(':', $groupBy, 2);
            if (!in_array($granularity, self::GRANULARITIES, true) || !in_array($target, self::DATE_ATTRIBUTES, true)) {
                throw new InvalidArgumentException(
                    "Invalid date groupBy '{$groupBy}'; use day|week|month|year : dateCreated|dateUpdated|postDate",
                );
            }

            return ['kind' => 'date', 'target' => $target, 'granularity' => $granularity];
        }

        return ['kind' => 'field', 'target' => $groupBy, 'granularity' => null];
    }

    public static function dateKey(?DateTimeInterface $date, string $granularity): string {
        if ($date === null) {
            return self::EMPTY_KEY;
        }

        return match ($granularity) {
            'day' => $date->format('Y-m-d'),
            'week' => $date->format('o-\WW'),
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => throw new InvalidArgumentException("Unknown granularity '{$granularity}'"),
        };
    }

    /**
     * @return array{total: int, buckets?: list<array{key: string, count: int}>, truncated?: bool}
     */
    public function collect(EntryQuery $query, ?string $groupBy): array {
        $total = (int) $query->count();
        if ($groupBy === null) {
            return ['total' => $total];
        }

        $parsed = self::parseGroupBy($groupBy);
        if ($parsed['kind'] === 'field') {
            $this->assertFieldExists($parsed['target'], $query);
        }

        $counts = [];
        foreach ($query->batch(100) as $batch) {
            foreach ($batch as $entry) {
                foreach ($this->keysFor($entry, $parsed) as $key) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        return ['total' => $total] + $this->format($counts, $parsed['kind']);
    }

    /**
     * @param array{kind: string, target: string, granularity: ?string} $parsed
     * @return list<string>
     */
    private function keysFor(Entry $entry, array $parsed): array {
        return match ($parsed['kind']) {
            'attribute' => [$this->attributeKey($entry, $parsed['target'])],
            'date' => [self::dateKey($entry->{$parsed['target']}, (string) $parsed['granularity'])],
            'field' => $this->fieldKeys($entry, $parsed['target']),
            default => [self::EMPTY_KEY],
        };
    }

    private function attributeKey(Entry $entry, string $target): string {
        $value = match ($target) {
            'status' => $entry->getStatus(),
            'type' => $entry->getType()->handle,
            'section' => $entry->getSection()?->handle,
            'site' => $entry->getSite()->handle,
            'author' => $entry->getAuthor()?->username,
            default => null,
        };

        return $value !== null && $value !== '' ? $value : self::EMPTY_KEY;
    }

    /**
     * Relation values bucket by related title; multi-value fields count once
     * per value; everything empty lands in the shared (empty) bucket.
     *
     * @return list<string>
     */
    private function fieldKeys(Entry $entry, string $handle): array {
        $value = $entry->getFieldValue($handle);

        if ($value instanceof ElementQueryInterface) {
            $titles = array_map(
                static fn (ElementInterface $related): string => (string) ($related->title ?? $related->id),
                $value->status(null)->all(),
            );

            return $titles === [] ? [self::EMPTY_KEY] : $titles;
        }

        if (is_iterable($value) && !is_string($value)) {
            $keys = [];
            foreach ($value as $item) {
                $keys[] = $this->scalarKey($item);
            }

            return $keys === [] ? [self::EMPTY_KEY] : $keys;
        }

        return [$this->scalarKey($value)];
    }

    private function scalarKey(mixed $value): string {
        if (in_array($value, [null, '', []], true)) {
            return self::EMPTY_KEY;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return self::EMPTY_KEY;
    }

    /**
     * @param array<string, int> $counts
     * @return array{buckets: list<array{key: string, count: int}>, truncated?: bool}
     */
    private function format(array $counts, string $kind): array {
        $kind === 'date' ? ksort($counts) : arsort($counts);

        $truncated = count($counts) > self::MAX_BUCKETS;
        $counts = array_slice($counts, 0, self::MAX_BUCKETS, true);

        $buckets = [];
        foreach ($counts as $key => $count) {
            $buckets[] = ['key' => (string) $key, 'count' => $count];
        }

        return ['buckets' => $buckets] + ($truncated ? ['truncated' => true] : []);
    }

    private function assertFieldExists(string $handle, EntryQuery $query): void {
        if (Craft::$app->getFields()->getFieldByHandle($handle) === null) {
            throw new InvalidArgumentException("Unknown groupBy '{$handle}': not an attribute, date bucket, or field handle");
        }

        // Eager-load relations so field-value bucketing does not query per entry.
        $query->with([$handle]);
    }
}
