<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\base\Element;
use craft\elements\Asset;
use craft\elements\db\ElementQuery;
use DateTime;
use DateTimeImmutable;
use Traversable;
use yii\db\ActiveRecord;

/**
 * Shared serializer for converting Craft objects to JSON-safe arrays.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Serializer {
    private const int MAX_DEPTH = 5;

    private const int MAX_ARRAY_ITEMS = 100;

    private const int MAX_QUERY_PREVIEW = 10;

    /**
     * Serialize any value to JSON-safe format with depth tracking.
     */
    public static function serialize(mixed $value, int $depth = 0): mixed {
        if ($depth > self::MAX_DEPTH) {
            return '[max depth reached]';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTime || $value instanceof DateTimeImmutable) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Asset) {
            return self::serializeAssetReference($value);
        }

        if ($value instanceof Element) {
            return self::serializeElementReference($value);
        }

        if ($value instanceof ElementQuery) {
            return self::serializeElementQuery($value, $depth);
        }

        if ($value instanceof ActiveRecord) {
            return [
                '__class' => $value::class,
                'attributes' => $value->getAttributes(),
            ];
        }

        if (is_array($value)) {
            return self::serializeArray($value, $depth);
        }

        if (is_object($value)) {
            return self::serializeObject($value, $depth);
        }

        return '[unserializable: ' . gettype($value) . ']';
    }

    /**
     * Serialize an asset reference (minimal representation).
     */
    private static function serializeAssetReference(Asset $asset): array {
        return [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'url' => $asset->getUrl(),
        ];
    }

    /**
     * Serialize an element reference (minimal representation).
     */
    private static function serializeElementReference(Element $element): array {
        return [
            '__class' => $element::class,
            'id' => $element->id,
            'title' => $element->title ?? null,
            'slug' => $element->slug ?? null,
        ];
    }

    /**
     * Serialize an element query with preview.
     */
    private static function serializeElementQuery(ElementQuery $query, int $depth): array {
        $count = $query->count();
        $elements = $query->limit(self::MAX_QUERY_PREVIEW)->all();

        return [
            '__class' => $query::class,
            '__note' => sprintf('ElementQuery (first %d of %d)', min($count, self::MAX_QUERY_PREVIEW), $count),
            'count' => $count,
            'elements' => array_map(
                fn ($el) => self::serialize($el, $depth + 1),
                $elements,
            ),
        ];
    }

    /**
     * Serialize an array with truncation.
     */
    private static function serializeArray(array $value, int $depth): array {
        $result = [];
        $count = 0;

        foreach ($value as $k => $v) {
            if ($count++ >= self::MAX_ARRAY_ITEMS) {
                $result['__truncated'] = sprintf('Array truncated at %d items', self::MAX_ARRAY_ITEMS);
                break;
            }
            $result[$k] = self::serialize($v, $depth + 1);
        }

        return $result;
    }

    /**
     * Serialize an object using available methods.
     */
    private static function serializeObject(object $value, int $depth): mixed {
        if (method_exists($value, 'toArray')) {
            return [
                '__class' => $value::class,
                'data' => self::serialize($value->toArray(), $depth + 1),
            ];
        }

        if (method_exists($value, '__toString')) {
            return (string) $value;
        }

        if ($value instanceof Traversable) {
            return [
                '__class' => $value::class,
                'items' => self::serialize(iterator_to_array($value), $depth + 1),
            ];
        }

        return [
            '__class' => $value::class,
        ];
    }
}
