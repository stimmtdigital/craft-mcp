<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * What is known about a link target on one site, as plain data.
 *
 * The status is the one Craft computes itself (Element::getStatus(), which
 * folds in the site-level enabled flag, the archived flag, post and expiry
 * dates and the staticStatuses setting), so this class never re-derives a
 * status from dates and cannot disagree with the control panel about one.
 */
final readonly class TargetState {
    public function __construct(
        public bool $deleted,
        public bool $draft,
        public ?string $status,
    ) {
    }
}
