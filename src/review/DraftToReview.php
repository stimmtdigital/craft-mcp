<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

use craft\elements\Entry;
use craft\models\Site;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\EntryResolver;

/**
 * The draft an id names for review, by the same rules stimmt_drafts_publish
 * resolves its id: a draft element id is that draft, and a canonical id stands
 * for its one pending non-provisional draft. Anything else is refused with the
 * id to call with instead.
 *
 * WHY the lookup admits provisional drafts and revisions only to refuse them:
 * a query that leaves them out answers their ids with "not found", for an
 * element the control panel and get_entry both show, and that sends an agent
 * hunting for an id that is right there. The refusal can only say what the id
 * is while it is still findable.
 */
final class DraftToReview {
    private const string TOOL = 'stimmt_drafts_diff';

    public static function resolve(int $id, ?Site $site): Entry {
        $entry = self::find($id, $site) ?? throw EntryResolver::missing($id);
        // Before anything is said about it: whether it is a revision, or which
        // drafts it has, is itself something a caller may not be allowed to see.
        Authorization::assertCanView($entry);
        self::assertReviewable($entry);

        return $entry->getIsDraft() ? $entry : self::onlyPendingDraft($entry, $site);
    }

    /**
     * A revision has nothing pending, and a provisional draft is someone's
     * unsaved edits that publishing would not apply: neither is a draft to
     * review. Public so the wording is testable on an element built by hand.
     */
    public static function assertReviewable(Entry $entry): void {
        if ($entry->getIsRevision()) {
            throw self::revision($entry);
        }

        if ($entry->isProvisionalDraft) {
            throw self::provisional($entry);
        }
    }

    /**
     * Newest first, non-provisional, every status: the drafts that
     * stimmt_drafts_publish would consider for the same canonical id.
     *
     * @return Entry[]
     */
    public static function pendingDrafts(Entry $canonical, ?Site $site): array {
        $query = Entry::find()
            ->draftOf($canonical->id)
            ->drafts()
            ->provisionalDrafts(false)
            ->status(null)
            ->orderBy(['dateUpdated' => SORT_DESC]);

        if ($site !== null) {
            $query->site($site);
        }

        // Only drafts the caller may see, so a refusal never names another
        // author's draft ids.
        Authorization::scopeQuery($query);

        return $query->all();
    }

    private static function find(int $id, ?Site $site): ?Entry {
        $query = Entry::find()->id($id)->status(null)->drafts(null)->provisionalDrafts(null)->revisions(null);

        if ($site !== null) {
            $query->site($site);
        }

        return $query->one();
    }

    private static function onlyPendingDraft(Entry $canonical, ?Site $site): Entry {
        $drafts = self::pendingDrafts($canonical, $site);

        if (count($drafts) === 1) {
            return $drafts[0];
        }

        if ($drafts === []) {
            throw new ToolCallException(
                "Entry {$canonical->id} has no pending draft, so there is nothing to compare with the live entry."
                . ' Call ' . self::TOOL . ' with a draft element id, or create a draft first.',
            );
        }

        $ids = implode(', ', array_map(static fn (Entry $draft): int => (int) $draft->id, $drafts));

        throw new ToolCallException(
            "Entry {$canonical->id} has multiple pending drafts; call " . self::TOOL . " with one draft element id: {$ids}",
        );
    }

    private static function revision(Entry $entry): ToolCallException {
        $canonicalId = (int) $entry->getCanonicalId();

        return new ToolCallException(
            "Entry {$entry->id} is a revision of entry {$canonicalId}: frozen history, with nothing pending to publish."
            . ' ' . self::TOOL . " compares a draft with its live entry. Call it with a draft id, or with {$canonicalId}"
            . ' to compare that entry\'s single pending draft.',
        );
    }

    private static function provisional(Entry $entry): ToolCallException {
        $canonicalId = (int) $entry->getCanonicalId();

        return new ToolCallException(
            "Entry {$entry->id} is a provisional draft: unsaved control-panel edits of entry {$canonicalId} that"
            . ' stimmt_drafts_publish would not apply. Save them as a draft in the control panel first, then call '
            . self::TOOL . ' with that draft\'s id.',
        );
    }
}
