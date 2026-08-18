<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use Craft;
use craft\elements\Entry;

/**
 * The sites an element exists on, reported so a caller can see how far an
 * operation actually went.
 *
 * WHY this is worth a query on the write path: several operations are wider
 * than the argument that names a site suggests. Deleting sets `dateDeleted` on
 * the element, so the entry is trashed on every site at once. Applying a draft
 * applies it everywhere the draft exists. Reordering a nested block writes
 * `elements_owners.sortOrder`, which has no site column at all, so the new
 * order is the order in every site. In each case the `site` argument only
 * chooses which row is located, and an agent that reads it as a scope is
 * wrong in the destructive direction.
 *
 * Saying so in the description helps whoever read the description. Saying so in
 * the response helps every caller, cannot drift out of date, and can be
 * asserted. That is the difference this class exists for.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Reach {
    /**
     * Site handles the element exists on, in Craft's own site order.
     *
     * A single-site install answers without a query: there is exactly one site
     * and it is the one the element is on.
     *
     * @return list<string>
     */
    public static function of(Entry $entry): array {
        $sites = Craft::$app->getSites();

        if (!Craft::$app->getIsMultiSite()) {
            return [$entry->getSite()->handle];
        }

        // The element's own id, not its canonical: deleting a draft trashes
        // that draft, and reporting the canonical's sites would describe an
        // operation that did not happen.
        $rows = Entry::find()
            ->id($entry->id)
            ->site('*')
            ->status(null)
            ->drafts(null)
            ->asArray()
            ->all();

        $handles = [];
        foreach ($rows as $row) {
            $site = isset($row['siteId']) ? $sites->getSiteById((int) $row['siteId']) : null;
            if ($site !== null) {
                $handles[$site->handle] = true;
            }
        }

        return array_keys($handles);
    }
}
