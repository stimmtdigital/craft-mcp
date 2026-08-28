<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\helpers\ElementHelper;

/**
 * Takes Craft's placeholder slugs back off the nested elements a write just
 * saved.
 *
 * WHY any are there: writing an existing Matrix block through a draft makes
 * Craft duplicate that block as a nested draft of its own
 * (Matrix::_createEntriesFromSerializedData), and duplicating an element runs
 * it past SlugValidator, which stamps ElementHelper::tempSlug() on any draft
 * whose slug is empty so a draft's URI can never collide with the canonical's.
 * A nested entry has no URI format, so the marker buys it nothing, and nothing
 * in Craft ever takes it off again: applying the owner's draft copies it onto
 * the canonical block, where `__temp_...` becomes stored per-site content that
 * reads back out of get_entry and goes straight back in on the next write.
 *
 * WHY null and not the canonical block's slug: the marker is only ever stamped
 * over an EMPTY slug, so its presence is itself the record that there was none.
 *
 * WHY after the save and not before it: the owner's own validation walks its
 * blocks (Matrix::validateEntries) and re-stamps every one it still finds
 * empty, so the marker can only come off once the save has shed the block's
 * draft status.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class NestedSlugs {
    public static function clearPlaceholders(ElementInterface $owner): void {
        foreach (self::nested($owner) as $element) {
            if (!ElementHelper::isTempSlug($element->slug)) {
                continue;
            }

            $element->slug = null;
            // Craft's own slug writer: the one elements_sites row, the events
            // other plugins listen for, and this element's caches invalidated.
            // Held to this element's own site, because the marker only ever
            // landed on the site the draft was written on, and the other sites
            // may be carrying a real slug that is none of this call's business.
            Craft::$app->getElements()->updateElementSlugAndUri($element, updateOtherSites: false, updateDescendants: false);
        }
    }

    /**
     * Every element the owner holds in a container field, and theirs in turn.
     *
     * @return iterable<ElementInterface>
     */
    private static function nested(ElementInterface $owner): iterable {
        foreach (self::children($owner) as $child) {
            yield $child;
            yield from self::nested($child);
        }
    }

    /**
     * @return ElementInterface[]
     */
    private static function children(ElementInterface $owner): array {
        $children = [];

        foreach (LayoutFields::of($owner->getFieldLayout()) as $handle => $field) {
            if (!$field instanceof ElementContainerFieldInterface) {
                continue;
            }

            $children = [...$children, ...self::owned($owner, $handle)];
        }

        return $children;
    }

    /**
     * The container field's own children, and only those.
     *
     * Only the result the write itself materialised is read: an untouched
     * container has no cached result, and a block Craft never redrafted cannot
     * be carrying a fresh marker. The ownership test is what keeps a merely
     * related element, whose slug is real and is not this owner's to rewrite,
     * out of reach.
     *
     * @return ElementInterface[]
     */
    private static function owned(ElementInterface $owner, string $handle): array {
        $value = $owner->getFieldValue($handle);

        $candidates = match (true) {
            $value instanceof ElementQuery => $value->getCachedResult() ?? [],
            $value instanceof ElementCollection => $value->all(),
            $value instanceof ElementInterface => [$value],
            default => [],
        };

        return array_filter(
            $candidates,
            static fn (mixed $child): bool => $child instanceof NestedElementInterface
                && $child->getOwnerId() === $owner->id,
        );
    }
}
