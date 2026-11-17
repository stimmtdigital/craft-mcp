<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\base\Element;
use craft\elements\Entry;

/**
 * Decides whether a link target is live, and if not, why.
 */
final class LinkHealth {
    /**
     * Null means the link works.
     *
     * WHY this order: each reason is the one an editor has to fix first. A
     * trashed element has to be restored before its status means anything,
     * and a draft has to be published before its enabled flag does.
     *
     * WHY an unknown status counts as live: only the statuses core defines are
     * known to hide an element. A plugin's element type with its own vocabulary
     * would otherwise be reported broken on a word this audit cannot read.
     */
    public static function reason(?TargetState $target): ?BrokenReason {
        if ($target === null) {
            return BrokenReason::Missing;
        }

        if ($target->deleted) {
            return BrokenReason::Deleted;
        }

        if ($target->draft) {
            return BrokenReason::DraftOnly;
        }

        return match ($target->status) {
            Element::STATUS_DISABLED, Element::STATUS_ARCHIVED => BrokenReason::Disabled,
            Entry::STATUS_PENDING => BrokenReason::Pending,
            Entry::STATUS_EXPIRED => BrokenReason::Expired,
            default => null,
        };
    }
}
