<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\base\ElementInterface;

/**
 * One link found in a scanned entry, before its target has been looked up.
 *
 * $siteId is the site the target is resolved on: the one a ref tag names, the
 * relation field's target site, or the source's own. Null when a ref tag names
 * a site this install does not have, which Craft renders as the tag's fallback
 * and this audit reports as a missing target.
 */
final readonly class LinkCandidate {
    /**
     * @param class-string<ElementInterface> $type
     */
    public function __construct(
        public int $sourceId,
        public string $field,
        public string $type,
        public int $targetId,
        public ?int $siteId,
    ) {
    }

    /** The same link reached twice (a Link field writes a relation row too) is reported once. */
    public function key(): string {
        return "{$this->sourceId}|{$this->field}|{$this->type}|{$this->targetId}|{$this->siteId}";
    }

    /** Targets are looked up per element type and site, one query each. */
    public function lookupKey(): string {
        return "{$this->type}|{$this->siteId}";
    }
}
