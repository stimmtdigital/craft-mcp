<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use Mcp\Exception\ToolCallException;

/**
 * The two position primitives for nested Matrix blocks: capture the position
 * a block currently holds under its owner, and move a block to a new one.
 * Reordering goes through Craft's supported owner-save path (set the field
 * value to the reordered block collection, save the owner) instead of raw
 * elements_owners writes: NestedElementManager::saveNestedElements() then
 * renumbers by value order inside its own transaction, skips unchanged rows,
 * and handles change tracking and cache invalidation itself.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class NestedPosition {
    /**
     * The 1-based position the element currently holds under its owner, or
     * null when the element is not nested. Reads the CANONICAL block's
     * current elements_owners row: a draft's own row still carries the
     * position from draft-creation time, so a block reordered after being
     * drafted must not publish back to that stale spot.
     */
    public static function capture(ElementInterface $element): ?int {
        if (!$element instanceof NestedElementInterface) {
            return null;
        }

        $ownerId = $element->getOwnerId();
        if ($ownerId === null) {
            return null;
        }

        $sortOrder = (new Query())
            ->select(['sortOrder'])
            ->from(Table::ELEMENTS_OWNERS)
            ->where(['elementId' => $element->getCanonicalId(), 'ownerId' => $ownerId])
            ->scalar();

        return is_numeric($sortOrder) ? (int) $sortOrder : null;
    }

    /**
     * Move a block to a 1-based position within its field on $owner and
     * return the position actually taken (positions past the end clamp to
     * the last slot). $blockId is the canonical block id; blocks are matched
     * on getCanonicalId() because under a saved owner draft an edited block
     * is a shed copy whose own element id differs from the canonical.
     */
    public static function move(Entry $owner, Matrix $field, int $blockId, int $position): int {
        $value = $owner->getFieldValue($field->handle);
        if (!$value instanceof ElementQueryInterface) {
            throw new ToolCallException(
                "Field '{$field->handle}' on entry {$owner->id} did not return a block query; the blocks cannot be reordered safely.",
            );
        }

        // status(null) is mandatory: core's owner-save cleanup deletes any
        // block missing from the value, so disabled siblings must ride along.
        $blocks = $value->status(null)->all();

        $byCanonicalId = [];
        foreach ($blocks as $block) {
            $byCanonicalId[(int) $block->getCanonicalId()] = $block;
        }

        if (!isset($byCanonicalId[$blockId])) {
            throw new ToolCallException(
                "Block {$blockId} is not among the blocks of field '{$field->handle}' on entry {$owner->id}; it may belong to a different owner, field, or site.",
            );
        }

        [$order, $taken] = self::reorder(array_keys($byCanonicalId), $blockId, $position);

        $value->setCachedResult(array_map(static fn (int $id): ElementInterface => $byCanonicalId[$id], $order));
        $owner->setFieldValue($field->handle, $value);

        // Saved without validation, deliberately, because a reorder changes no
        // value: the owner's attributes and every block's content are exactly
        // what was already stored, and only the order of existing children
        // moves. Validating anyway made the tool fail on data it did not author
        // and cannot fix. A block whose entry type was later removed from the
        // field is still valid content that Craft itself will keep and show; it
        // simply no longer passes the field's current rules, and refusing to
        // reorder its siblings because of it helps nobody. Nothing invalid is
        // introduced by this path: every value written back was read from the
        // same element moments earlier.
        if (!Craft::$app->getElements()->saveElement($owner, runValidation: false)) {
            throw new ToolCallException('Failed to reorder blocks: ' . json_encode($owner->getErrors()));
        }

        return $taken;
    }

    /**
     * Pure reorder arithmetic: remove $blockId from the id list, clamp
     * $position into [1..count], reinsert, and return the new order plus the
     * position taken. Separated from move() so the arithmetic tests without
     * a Craft application.
     *
     * @param int[] $ids canonical block ids in current field order
     * @return array{0: int[], 1: int}
     */
    public static function reorder(array $ids, int $blockId, int $position): array {
        if ($position < 1) {
            throw new ToolCallException("position must be 1 or greater, got {$position}");
        }

        $index = array_search($blockId, $ids, true);
        if ($index === false) {
            throw new ToolCallException("Block {$blockId} is not in the block list");
        }

        unset($ids[$index]);
        $ids = array_values($ids);

        $taken = min($position, count($ids) + 1);
        array_splice($ids, $taken - 1, 0, [$blockId]);

        return [$ids, $taken];
    }
}
