<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * A reference tag together with the content key it was found under.
 *
 * Craft 5 keys `elements_sites.content` by field layout element UID, not by
 * field handle, so the key is kept as found and translated to a handle by the
 * caller that has the field layout to hand.
 */
final readonly class FieldRef {
    public function __construct(
        public string $layoutUid,
        public RefTag $tag,
    ) {
    }
}
