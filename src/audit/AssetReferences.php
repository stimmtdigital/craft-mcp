<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\elements\Asset;

/**
 * The asset ids that content refers to through reference tags.
 *
 * Only tags that name an asset by id count. Craft's asset query ignores a
 * non-numeric ref, so `{asset:logo}` resolves to no particular asset and
 * cannot make any one of them "used".
 */
final class AssetReferences {
    /** @var array<int, true> */
    private array $ids = [];

    /**
     * @param list<RefTag> $tags
     */
    public function addTags(array $tags): void {
        foreach ($tags as $tag) {
            $this->add($tag);
        }
    }

    public function add(RefTag $tag): void {
        $id = $tag->id();
        if ($id === null || !$this->namesAsset($tag->handle)) {
            return;
        }

        $this->ids[$id] = true;
    }

    public function count(): int {
        return count($this->ids);
    }

    /**
     * @return list<int>
     */
    public function ids(): array {
        $ids = array_keys($this->ids);
        sort($ids);

        return $ids;
    }

    /**
     * Craft accepts the class name in place of the ref handle
     * (`{craft\elements\Asset:12}`), so both spellings name an asset.
     */
    private function namesAsset(string $handle): bool {
        return $handle === Asset::refHandle() || ltrim($handle, '\\') === Asset::class;
    }
}
