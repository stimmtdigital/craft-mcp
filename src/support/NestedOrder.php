<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\Db;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Position of a nested element within its owner's field.
 *
 * Ordering lives in elements_owners.sortOrder, held per owner rather than in
 * the element's own content, which is why a draft of the owner carries its own
 * copy. Craft's own control panel never hits the problem below: it drafts the
 * owner and rewrites the whole field on publish. Applying a draft of a lone
 * nested element takes a different path, where Craft's ownership save cannot
 * recover the previous sortOrder and falls through to max+1, silently moving
 * the element to the end of its field.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class NestedOrder {
    /**
     * Current sortOrder of the element a draft will be applied to, or null
     * when the element is not nested and ordering does not apply.
     */
    public static function capture(ElementInterface $element): ?int {
        if (!$element instanceof NestedElementInterface) {
            return null;
        }

        $ownerId = $element->getOwnerId();
        if ($ownerId === null) {
            return null;
        }

        // An unpublished draft is its own canonical, so this reads the row the
        // draft already holds; an edit draft reads the row of the element it
        // will be applied to.
        return self::sortOrderOf((int) $element->getCanonicalId(), $ownerId);
    }

    /**
     * Put a just-applied element back where it was, if applying moved it.
     */
    public static function restore(ElementInterface $element, ?int $sortOrder): void {
        if ($sortOrder === null || !$element instanceof NestedElementInterface) {
            return;
        }

        $ownerId = $element->getOwnerId();
        if ($ownerId === null || self::sortOrderOf((int) $element->id, $ownerId) === $sortOrder) {
            return;
        }

        Db::update(Table::ELEMENTS_OWNERS, ['sortOrder' => $sortOrder], [
            'elementId' => $element->id,
            'ownerId' => $ownerId,
        ]);

        self::invalidate($element, $ownerId);
    }

    /**
     * Move a nested element to a 1-based position among its live siblings,
     * renumbering the field contiguously. Positions past the end land last.
     *
     * Ordering is stored per owner, so passing $ownerId reorders one owner's
     * view of the field -- a draft of the owner carries its own rows, which is
     * what lets a reorder be drafted and applied like any other write.
     *
     * @return int the position actually taken
     */
    public static function place(NestedElementInterface $element, int $position, ?int $ownerId = null): int {
        $ownerId ??= $element->getOwnerId();

        // getField() resolves the owner's layout, so an orphaned or
        // misconfigured nested element throws rather than returning null.
        // Craft only started catching that internally in recent 5.x, so guard
        // it here instead of relying on the running version to be forgiving.
        try {
            $fieldId = $element->getField()?->id;
        } catch (InvalidConfigException) {
            return $position;
        }

        if ($ownerId === null || $fieldId === null) {
            return $position;
        }

        $siblings = self::siblingIds($ownerId, $fieldId);
        $id = (int) $element->id;

        // Renumbering from the live siblings alone also drops the gaps left by
        // trashed blocks, whose elements_owners rows survive a soft delete and
        // otherwise inflate every later max+1.
        $ordered = array_values(array_filter($siblings, static fn (int $sibling): bool => $sibling !== $id));
        $index = max(0, min($position - 1, count($ordered)));
        array_splice($ordered, $index, 0, [$id]);

        foreach ($ordered as $offset => $siblingId) {
            Db::update(Table::ELEMENTS_OWNERS, ['sortOrder' => $offset + 1], [
                'elementId' => $siblingId,
                'ownerId' => $ownerId,
            ]);
        }

        self::invalidate($element, $ownerId);

        return $index + 1;
    }

    /**
     * Invalidate for the block and its owner, because neither call covers the
     * other. Craft derives its tags from whichever element it is handed: the
     * block contributes its own id plus the container field, the owner
     * contributes its section. A reorder changes the field's composition and
     * what the owner renders, so both sets matter, and over-invalidating costs
     * a cache miss where under-invalidating serves a stale page.
     *
     * Invalidating a nested element also walks to its owner, which throws on
     * an orphan. The rows are already written by this point, so a failure here
     * must not turn a successful reorder into a reported error.
     */
    private static function invalidate(ElementInterface $element, ?int $ownerId): void {
        $owner = $ownerId === null
            ? null
            : Entry::find()->id($ownerId)->drafts(null)->status(null)->one();

        foreach ([$element, $owner] as $target) {
            if (!$target instanceof ElementInterface) {
                continue;
            }

            try {
                Craft::$app->getElements()->invalidateCachesForElement($target);
            } catch (Throwable) {
                // Stale caches are recoverable; a thrown reorder is not.
            }
        }
    }

    private static function sortOrderOf(int $elementId, int $ownerId): ?int {
        $sortOrder = (new Query())
            ->select('sortOrder')
            ->from(Table::ELEMENTS_OWNERS)
            ->where(['elementId' => $elementId, 'ownerId' => $ownerId])
            ->scalar();

        return $sortOrder === false || $sortOrder === null ? null : (int) $sortOrder;
    }

    /**
     * Live sibling ids in current order. Drafts and revisions are excluded so
     * a pending draft of a sibling never takes its place in the sequence.
     *
     * @return int[]
     */
    private static function siblingIds(int $ownerId, int $fieldId): array {
        return array_map(intval(...), (new Query())
            ->select('eo.elementId')
            ->from(['eo' => Table::ELEMENTS_OWNERS])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[eo.elementId]]')
            ->innerJoin(['en' => Table::ENTRIES], '[[en.id]] = [[eo.elementId]]')
            ->where([
                'eo.ownerId' => $ownerId,
                'en.fieldId' => $fieldId,
                'e.dateDeleted' => null,
                'e.draftId' => null,
                'e.revisionId' => null,
            ])
            ->orderBy(['eo.sortOrder' => SORT_ASC])
            ->column());
    }
}
