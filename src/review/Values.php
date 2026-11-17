<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * What the review tools need to know about a Reader payload value: whether it
 * is empty, whether it is a list of nested blocks, and whether two of them say
 * the same thing.
 *
 * WHY "the same thing" needs its own definition rather than ===: a payload is
 * JSON-shaped, so an object's key order carries no meaning, and a block carries
 * keys that are bookkeeping rather than content (its position, which the block
 * diff reports on its own terms, and the control panel's collapsed state).
 * Comparing raw arrays reported a reordered object or a folded block as an
 * edit nobody made.
 */
final class Values {
    /** Keys of a block that describe how it is shown or ordered, not what it says. */
    private const array BLOCK_BOOKKEEPING = ['position', 'collapsed'];

    /**
     * Null, an empty string and an empty list all mean "nothing here". False
     * and 0 do not: a lightswitch turned off is a value.
     */
    public static function isEmpty(mixed $value): bool {
        return in_array($value, [null, '', []], true);
    }

    /**
     * A Matrix or Addresses value as the Reader writes it: blocks keyed by
     * element id, each carrying its own field values under "fields". Told
     * apart from a ContentBlock value, which is ONE block's {fields: ...}.
     */
    public static function isBlockList(mixed $value): bool {
        if (!is_array($value) || $value === [] || isset($value['fields'])) {
            return false;
        }

        return array_all($value, static fn (mixed $block): bool => self::isBlock($block));
    }

    public static function same(mixed $a, mixed $b): bool {
        if (self::isEmpty($a) && self::isEmpty($b)) {
            return true;
        }

        return self::normalize($a) === self::normalize($b);
    }

    /**
     * The value with object keys sorted and block bookkeeping dropped, at
     * every depth. Lists keep their order: the order of related entries or
     * table rows is content.
     */
    public static function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isBlockList($value)) {
            return self::normalizeBlocks($value);
        }

        if (self::isBlock($value)) {
            $value = array_diff_key($value, array_flip(self::BLOCK_BOOKKEEPING));
        }

        $normalized = array_map(self::normalize(...), $value);
        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * Block keys in field order. The Reader numbers blocks with "position"
     * because a transport may reorder numeric object keys; when the numbers
     * are there they win, and otherwise the order the blocks arrived in holds.
     *
     * @param array<array-key, mixed> $blocks
     * @return list<string>
     */
    public static function blockOrder(array $blocks): array {
        $keys = array_map(strval(...), array_keys($blocks));
        $arrival = array_flip($keys);
        usort($keys, static fn (string $a, string $b): int => [self::position($blocks[$a]), $arrival[$a]]
            <=> [self::position($blocks[$b]), $arrival[$b]]);

        return $keys;
    }

    /**
     * A block list as an ordered list of keyed blocks. WHY not the keyed map
     * itself: sorting its keys like any other object's would erase the block
     * order, and dropping "position" with the bookkeeping would erase it
     * twice, so a reordered field compared as unchanged.
     *
     * @param array<array-key, mixed> $blocks
     * @return list<array<array-key, mixed>>
     */
    private static function normalizeBlocks(array $blocks): array {
        return array_map(
            static fn (string $key): array => ['key' => $key, 'block' => self::normalize($blocks[$key])],
            self::blockOrder($blocks),
        );
    }

    private static function position(mixed $block): int {
        return is_array($block) && is_int($block['position'] ?? null) ? $block['position'] : PHP_INT_MAX;
    }

    /**
     * One nested block: a Matrix block inside a list, or a ContentBlock value,
     * which is a single block's {fields: ...} on its own.
     */
    public static function isBlock(mixed $value): bool {
        return is_array($value) && isset($value['fields']) && is_array($value['fields']);
    }
}
