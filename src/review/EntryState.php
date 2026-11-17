<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

use craft\elements\Entry;
use craft\models\Section;
use stimmt\craft\Mcp\elements\Attributes;
use stimmt\craft\Mcp\elements\refs\Keys;

/**
 * The native attributes of an entry that a draft can change, as plain values
 * FieldChanges can compare: the half of a draft diff the Reader's field map
 * does not cover.
 */
final readonly class EntryState {
    /** Display names, in the order a reviewer reads an entry's settings. */
    public const array LABELS = [
        'title' => 'Title',
        'slug' => 'Slug',
        'type' => 'Entry type',
        'enabled' => 'Enabled',
        'enabledForSite' => 'Enabled for this site',
        'postDate' => 'Post date',
        'expiryDate' => 'Expiry date',
        'parent' => 'Parent',
    ];

    public function __construct(
        private Keys $keys = new Keys(),
    ) {
    }

    /**
     * @param Entry|null $canonical the live entry, whose parent a draft keeps
     *                              until it is given a new one
     * @return array<string, mixed>
     */
    public function of(Entry $entry, ?Entry $canonical = null): array {
        return [
            'title' => $entry->title,
            'slug' => $entry->slug,
            'type' => Attributes::value($entry, 'typeHandle'),
            'enabled' => $entry->enabled,
            'enabledForSite' => $entry->getEnabledForSite(),
            'postDate' => Attributes::value($entry, 'postDate'),
            'expiryDate' => Attributes::value($entry, 'expiryDate'),
            'parent' => $this->parent($this->parentHolder($entry, $canonical)),
        ];
    }

    /**
     * WHY a draft may have to borrow the live entry's parent: Craft only puts
     * a draft into the section's structure when the draft changes the parent
     * (Entry::afterSave() calls _placeInStructure() on hasNewParent()). A
     * draft outside the structure has no ancestors to read, and taking that
     * as "no parent" reported every draft of a nested page as moving it to
     * the top level.
     */
    private function parentHolder(Entry $entry, ?Entry $canonical): Entry {
        $outsideStructure = $entry->getIsDraft() && $entry->lft === null;

        return $outsideStructure && $canonical !== null ? $canonical : $entry;
    }

    /**
     * @return array<string, mixed>|int|null the parent's natural key, its id when it has none
     */
    private function parent(Entry $entry): array|int|null {
        // Only a structure section has parents; anything else would cost an
        // ancestors query to learn nothing.
        if ($entry->getSection()?->type !== Section::TYPE_STRUCTURE) {
            return null;
        }

        $parentId = $entry->getParentId();
        if ($parentId === null) {
            return null;
        }

        return $this->keys->keyFor(Entry::class, $parentId, $entry->getSite()->handle) ?? $parentId;
    }
}
