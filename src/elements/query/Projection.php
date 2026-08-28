<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\query;

use Craft;
use craft\elements\Entry;
use InvalidArgumentException;
use stimmt\craft\Mcp\elements\Attributes;
use stimmt\craft\Mcp\elements\LayoutFields;
use stimmt\craft\Mcp\elements\Reader;

/**
 * Slim list_entries rows: id and title always, plus only the requested
 * attributes and field values. Keeps large-list analysis affordable where the
 * full payload would drown the client.
 *
 * A projection is asked for once and applied to every row, and one query can
 * return entries of several types (a Matrix block is an entry too), so a
 * requested handle is validated against the install rather than against
 * whichever entry happens to be in hand. A row whose own type has no such
 * field simply does not carry it; only a name no field anywhere answers to is
 * the caller's mistake.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Projection {
    public const array ATTRIBUTES = [
        'slug', 'status', 'url', 'cpEditUrl', 'postDate', 'expiryDate',
        'dateCreated', 'dateUpdated', 'sectionHandle', 'typeHandle', 'siteHandle', 'authorId',
    ];

    /** Every row carries these by construction, so naming one is redundant, not wrong. */
    private const array ALWAYS = ['id', 'title'];

    public function __construct(
        private Reader $reader,
    ) {
    }

    /**
     * @param string[] $fields attribute names or field handles
     */
    public function row(Entry $entry, array $fields, ?string $site = null): array {
        [$attributes, $handles] = $this->split($entry, $fields);

        $row = ['id' => $entry->id, 'title' => $entry->title];
        foreach ($attributes as $attribute) {
            $row[$attribute] = Attributes::value($entry, $attribute);
        }

        if ($handles !== []) {
            $row['fields'] = $this->reader->readFields($entry, $handles, $site);
        }

        return $row;
    }

    /**
     * @param string[] $fields
     * @return array{0: string[], 1: string[]}
     */
    private function split(Entry $entry, array $fields): array {
        $layout = LayoutFields::of($entry->getFieldLayout());
        $attributes = [];
        $handles = [];

        foreach ($fields as $name) {
            match (true) {
                in_array($name, self::ALWAYS, true) => null,
                in_array($name, self::ATTRIBUTES, true) => $attributes[] = $name,
                isset($layout[$name]) => $handles[] = $name,
                $this->exists($name) => null,
                default => throw new InvalidArgumentException(
                    "Unknown projection field '{$name}'. Attributes: " . implode(', ', self::ATTRIBUTES)
                    . '. For field handles use list_fields, or describe_entry_schema for the handles of one section.',
                ),
            };
        }

        return [$attributes, $handles];
    }

    /**
     * Whether the install has a field by this handle at all. Reached only for
     * a handle this entry's own layout does not carry, which is the normal
     * case for a mixed result set rather than an error.
     */
    private function exists(string $handle): bool {
        return Craft::$app->getFields()->getFieldByHandle($handle) !== null;
    }
}
