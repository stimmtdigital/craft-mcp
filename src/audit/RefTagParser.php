<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\audit;

use craft\services\Elements;

/**
 * Finds the reference tags in a piece of text.
 *
 * WHY Craft's own pattern rather than a copy: a tag counts as a link exactly
 * when Craft's parseRefs() would resolve it, so the grammar (ref handle or
 * class, id or slug, optional @site, :attribute and ||fallback) has one source
 * of truth. A copy would drift on the next Craft release and report links
 * Craft never renders, or miss ones it does.
 */
final class RefTagParser {
    /**
     * @return list<RefTag>
     */
    public static function parse(string $text): array {
        // Every tag opens with a brace; most content strings have none.
        if (!str_contains($text, '{')) {
            return [];
        }

        preg_match_all(Elements::REF_TAG_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        return array_map(self::tag(...), $matches);
    }

    /**
     * @param array<array-key, string|null> $match
     */
    private static function tag(array $match): RefTag {
        return new RefTag(
            (string) $match['elementType'],
            trim((string) $match['ref']),
            $match['site'] ?? null,
            $match['attr'] ?? null,
        );
    }
}
