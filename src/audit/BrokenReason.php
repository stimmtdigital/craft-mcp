<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * Why a link does not lead to a live element on the site it is shown on.
 */
enum BrokenReason: string {
    /** The target is in the trash (elements.dateDeleted is set). */
    case Deleted = 'deleted';

    /** Disabled globally or for the target site, or archived. */
    case Disabled = 'disabled';

    /** An entry whose expiry date has passed. */
    case Expired = 'expired';

    /** An entry whose post date is in the future, or not set. */
    case Pending = 'pending';

    /** No element with that id exists on the site the link is resolved for. */
    case Missing = 'missing';

    /** The target is a draft rather than a published element. */
    case DraftOnly = 'draft-only';
}
