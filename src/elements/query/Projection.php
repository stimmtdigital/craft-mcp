<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\query;

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
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Projection {
    public const array ATTRIBUTES = [
        'slug', 'status', 'url', 'cpEditUrl', 'postDate', 'expiryDate',
        'dateCreated', 'dateUpdated', 'sectionHandle', 'typeHandle', 'siteHandle', 'authorId',
    ];

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
                in_array($name, self::ATTRIBUTES, true) => $attributes[] = $name,
                isset($layout[$name]) => $handles[] = $name,
                default => throw new InvalidArgumentException(
                    "Unknown projection field '{$name}'. Attributes: " . implode(', ', self::ATTRIBUTES)
                    . '. Field handles: ' . implode(', ', array_keys($layout)),
                ),
            };
        }

        return [$attributes, $handles];
    }
}
