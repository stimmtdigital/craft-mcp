<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * Renames a draft's nested blocks to the ids of the live blocks they derive
 * from, at every depth of a Reader field map.
 *
 * WHY: a draft does not edit the live entry's Matrix blocks in place. A block
 * the draft never touched is shared with the live entry and keeps its id, but
 * the moment the draft edits one, Craft duplicates it into a block of the
 * draft's own with a NEW id and the live block's id as its canonicalId
 * (NestedElementManager::duplicateNestedElements). Compared by id, that edit
 * reads as the old block removed and a new one added. Mapping the copy's id
 * back to the canonical one first is what lets the diff say "this block
 * changed".
 */
final class Rekey {
    /**
     * @param array<array-key, mixed> $fields a Reader field map
     * @param array<array-key, int|string> $aliases draft block id => canonical block id
     * @return array<array-key, mixed>
     */
    public static function fields(array $fields, array $aliases): array {
        if ($aliases === []) {
            return $fields;
        }

        return array_map(static fn (mixed $value): mixed => self::value($value, $aliases), $fields);
    }

    /**
     * @param array<array-key, int|string> $aliases
     */
    private static function value(mixed $value, array $aliases): mixed {
        if (Values::isBlockList($value)) {
            return self::blocks($value, $aliases);
        }

        if (Values::isBlock($value)) {
            return self::block($value, $aliases);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $blocks
     * @param array<array-key, int|string> $aliases
     * @return array<array-key, mixed>
     */
    private static function blocks(array $blocks, array $aliases): array {
        $renamed = [];
        foreach ($blocks as $key => $block) {
            $renamed[$aliases[$key] ?? $key] = self::block($block, $aliases);
        }

        return $renamed;
    }

    /**
     * @param array<array-key, int|string> $aliases
     * @return array<array-key, mixed>
     */
    private static function block(array $block, array $aliases): array {
        return [...$block, 'fields' => self::fields($block['fields'], $aliases)];
    }
}
