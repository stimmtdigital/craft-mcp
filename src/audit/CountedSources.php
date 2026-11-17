<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * Which referencing elements make an asset "used".
 *
 * Counted: canonical elements and drafts that are not in the trash.
 * Not counted: revisions and trashed elements.
 *
 * WHY drafts count: a draft is someone's unfinished work, and an asset removed
 * from under it breaks that work the moment it is published. WHY revisions do
 * not: nearly every asset ever attached somewhere sits in some entry's
 * history, so counting them would leave almost nothing unused, and a revision
 * is restored only by a person who can see what it lost. WHY the trash does
 * not: a trashed element is on its way out, and its references go with it
 * when garbage collection runs.
 *
 * One condition, used by the relations check and the reference-tag scan
 * alike, so the two halves of "used" cannot disagree about what counts.
 */
final class CountedSources {
    /**
     * A query-builder condition on an `elements` table under $alias.
     *
     * @return array<int, mixed>
     */
    public static function condition(string $alias): array {
        return [
            'and',
            ["{$alias}.revisionId" => null],
            ["{$alias}.dateDeleted" => null],
        ];
    }
}
