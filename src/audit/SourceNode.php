<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

/**
 * One element whose content belongs to a scanned entry: the entry itself, or
 * a nested entry (a Matrix block) somewhere beneath it.
 *
 * $path is the chain of Matrix field handles leading to it, each followed by
 * a dot, so a link found in field "body" of a block in "contentBlocks" is
 * reported as "contentBlocks.body". The entry itself has an empty path.
 */
final readonly class SourceNode {
    public function __construct(
        public int $rootId,
        public string $path,
        public ?int $fieldLayoutId,
    ) {
    }

    public function field(string $handle): string {
        return $this->path . $handle;
    }
}
