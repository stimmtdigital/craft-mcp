<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\fields\Addresses;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use stimmt\craft\Mcp\elements\LayoutFields;

/**
 * For a draft, the id of the live block each of its own nested block copies
 * derives from, at every depth: the map Rekey needs.
 *
 * WHY read off the elements rather than the payload: the Reader writes a block
 * under its own id and nothing else, and a block's canonicalId is the one fact
 * that ties a draft's copy to the live block it was made from. The walk reads
 * the same values the Reader does (every status, so a disabled block is
 * matched too) and descends through nested blocks' own fields, because a
 * Matrix inside a Matrix block is copied the same way.
 */
final class BlockAliases {
    /**
     * @return array<int, int> draft block id => canonical block id, only where they differ
     */
    public static function of(ElementInterface $owner): array {
        $aliases = [];
        foreach (LayoutFields::of($owner->getFieldLayout()) as $handle => $field) {
            $aliases += self::inField($field, $owner->getFieldValue($handle));
        }

        return $aliases;
    }

    /**
     * @return array<int, int>
     */
    private static function inField(FieldInterface $field, mixed $value): array {
        // Containers only, by field type: a relation field's value is an
        // element query too, and walking into related entries would read
        // other entries' blocks as this draft's own.
        if (($field instanceof Matrix || $field instanceof Addresses) && $value instanceof ElementQueryInterface) {
            return self::inBlocks((clone $value)->status(null)->all());
        }

        // A ContentBlock value is one nested element; its own blocks may be
        // copies too.
        return $field instanceof ContentBlock && $value instanceof ElementInterface ? self::of($value) : [];
    }

    /**
     * @param ElementInterface[] $blocks
     * @return array<int, int>
     */
    private static function inBlocks(array $blocks): array {
        $aliases = [];
        foreach ($blocks as $block) {
            $canonicalId = (int) $block->getCanonicalId();
            if ($canonicalId !== (int) $block->id) {
                $aliases[(int) $block->id] = $canonicalId;
            }

            $aliases += self::of($block);
        }

        return $aliases;
    }
}
