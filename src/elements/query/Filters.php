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
use InvalidArgumentException;
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
                ?? throw new InvalidArgumentException("No user found for '{$author}'");
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
     * unbounded. Accepts anything Craft's DateTimeHelper parses (ISO dates).
     *
     * @return list<string>|null
     */
    public static function dateParam(?string $after, ?string $before): ?array {
        if ($after === null && $before === null) {
            return null;
        }

        return array_values(array_filter([
            'and',
            $after !== null ? ">= {$after}" : null,
            $before !== null ? "< {$before}" : null,
        ]));
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
            ?? throw new InvalidArgumentException("Unknown field handle '{$handle}' in filters");

        if (method_exists(EntryQuery::class, $handle) || property_exists(EntryQuery::class, $handle)) {
            throw new InvalidArgumentException(
                "Field handle '{$handle}' collides with an entry query parameter and cannot be used in filters; use search instead",
            );
        }

        if (!is_array($value)) {
            return $value;
        }

        if (!$field instanceof BaseRelationField) {
            throw new InvalidArgumentException(
                "Field '{$handle}' is not a relation field; pass a scalar value, ':empty:', or ':notempty:'",
            );
        }

        return $this->keys->idFor($field::elementType(), $value, $site)
            ?? throw new InvalidArgumentException(
                "No element found for the natural key in filters.{$handle}: " . json_encode($value),
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

            $id = $this->keys->idFor($type, $key, $site);
            if ($id !== null) {
                return $id;
            }
        }

        throw new InvalidArgumentException(
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
