<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\query;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fields\BaseRelationField;
use stimmt\craft\Mcp\elements\InvalidInput;
use stimmt\craft\Mcp\elements\refs\Keys;

/**
 * Shared entry-query filters for the read tools (list_entries, count_entries).
 * Translates the agent-facing filter params (field values with :empty: tokens,
 * natural keys, ISO dates, usernames) onto a Craft entry query. Unresolvable
 * input throws instead of silently matching everything.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Filters {
    /** The leading Y-m-d of a date bound, the one shape a calendar can disprove. */
    private const string ISO_DATE = '/^(\d{4})-(\d{1,2})-(\d{1,2})/';

    /**
     * relatedTo shape detection tries these in order; category before tag
     * because their key shapes are identical and categories are the common
     * relation target.
     */
    private const array RELATABLE_TYPES = [
        Entry::class,
        Asset::class,
        Category::class,
        Tag::class,
        User::class,
    ];

    public function __construct(
        private Keys $keys = new Keys(),
    ) {
    }

    /**
     * @param array<string, mixed>|null  $filters   field-value filters: {handle: value}
     * @param array<string, string>|null $relatedTo natural key of the relation target
     */
    public function apply(
        EntryQuery $query,
        ?array $filters = null,
        ?array $relatedTo = null,
        ?string $author = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
        ?string $site = null,
    ): void {
        foreach ($filters ?? [] as $handle => $value) {
            $query->{$handle}($this->fieldValue((string) $handle, $value, $site));
        }

        if ($relatedTo !== null) {
            $query->relatedTo($this->resolveRelatedTo($relatedTo, $site));
        }

        if ($author !== null) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($author)
                ?? throw new InvalidInput("No user found for '{$author}'");
            $query->authorId($user->id);
        }

        foreach ([
            'dateUpdated' => self::dateParam($updatedAfter, $updatedBefore),
            'dateCreated' => self::dateParam($createdAfter, $createdBefore),
        ] as $attribute => $param) {
            if ($param !== null) {
                $query->{$attribute}($param);
            }
        }
    }

    /**
     * Craft date-range param: ['and', '>= after', '< before'], or null when
     * unbounded. Bounds are validated here rather than handed to Craft as-is:
     * a value Craft cannot parse becomes an empty string on the way to the
     * database, and the caller gets a raw SQL error carrying the whole
     * statement instead of an answer about their typo.
     *
     * @return list<string>|null
     */
    public static function dateParam(?string $after, ?string $before): ?array {
        if ($after === null && $before === null) {
            return null;
        }

        return array_values(array_filter([
            'and',
            $after !== null ? '>= ' . self::bound($after) : null,
            $before !== null ? '< ' . self::bound($before) : null,
        ]));
    }

    /**
     * The bound, once it is known to name a real instant. Returns the caller's
     * own string so Craft still does the parsing that matters; this only
     * refuses what Craft would either choke on or quietly turn into a
     * different date than the one that was asked for.
     */
    private static function bound(string $value): string {
        if (self::isDate($value)) {
            return $value;
        }

        throw new InvalidInput(
            "Invalid date '{$value}' in a date filter (createdAfter, createdBefore, updatedAfter, updatedBefore). "
            . "Pass a date Craft parses, such as '2026-01-31', '2026-01-31 14:00', or 'now'.",
        );
    }

    private static function isDate(string $value): bool {
        // A blank bound names no instant, and the two ways of spelling one
        // disagreed underneath: strtotime refuses '' and reads ' ' as NOW, so
        // a whitespace bound became a silent filter on the moment of the call.
        // createdAfter: " " then answered "0 entries" about a section holding
        // sixty-one, which is worse than the typos this guard already catches,
        // because a caller has no reason to doubt it.
        if (trim($value) === '') {
            return false;
        }

        // Craft reads a bare number as a unix timestamp; strtotime does not.
        if (is_numeric($value)) {
            return true;
        }

        if (strtotime($value) === false) {
            return false;
        }

        if (preg_match(self::ISO_DATE, trim($value), $parts) !== 1) {
            return true;
        }

        // An impossible day rolls over into the next month rather than being
        // refused (2026-02-30 answers about March 2, 2026-13-45 about 2027),
        // which is a confident answer to a question nobody asked.
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    /**
     * A field filter value: scalars and :empty:/:notempty: pass through to
     * Craft's field query params; arrays are natural keys, resolved to the
     * related element id (relation fields only). Handles that shadow a real
     * query param would silently set that param instead of filtering the
     * field, so they are refused outright.
     */
    private function fieldValue(string $handle, mixed $value, ?string $site): mixed {
        $field = Craft::$app->getFields()->getFieldByHandle($handle)
            ?? throw new InvalidInput("Unknown field handle '{$handle}' in filters");

        if (method_exists(EntryQuery::class, $handle) || property_exists(EntryQuery::class, $handle)) {
            throw new InvalidInput(
                "Field handle '{$handle}' collides with an entry query parameter and cannot be used in filters; use search instead",
            );
        }

        if (!is_array($value)) {
            return $value;
        }

        if (!$field instanceof BaseRelationField) {
            throw new InvalidInput(
                "Field '{$handle}' is not a relation field; pass a scalar value, ':empty:', or ':notempty:'",
            );
        }

        $resolution = $this->keys->resolve($field::elementType(), $value, $site);

        return $resolution->id ?? throw new InvalidInput(
            $resolution->ambiguous
                ? "The natural key in filters.{$handle} matches more than one element; filter by id instead: " . json_encode($value)
                : "No element found for the natural key in filters.{$handle}: " . json_encode($value),
        );
    }

    /**
     * @param array<string, string> $key
     */
    private function resolveRelatedTo(array $key, ?string $site): int {
        foreach (self::RELATABLE_TYPES as $type) {
            if (!$this->matchesShape($type, $key)) {
                continue;
            }

            $resolution = $this->keys->resolve($type, $key, $site);
            if ($resolution->ambiguous) {
                throw new InvalidInput(
                    'The relatedTo key matches more than one element; relate by id instead: ' . json_encode($key),
                );
            }

            if ($resolution->id !== null) {
                return $resolution->id;
            }
        }

        throw new InvalidInput(
            'relatedTo did not match any element; expected a natural key like '
            . '{"section","slug"}, {"volume","filename"}, {"group","slug"}, or {"username"}, got: '
            . json_encode($key),
        );
    }

    /**
     * @param array<string, string> $key
     */
    private function matchesShape(string $type, array $key): bool {
        $shape = $this->keys->keyShape($type);
        if ($shape === null) {
            return false;
        }

        $required = array_filter($shape, static fn (string $part): bool => !str_ends_with($part, '?'));

        return array_all($required, static fn (string $part): bool => isset($key[$part]));
    }
}
