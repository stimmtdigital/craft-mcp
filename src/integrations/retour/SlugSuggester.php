<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\retour;

/**
 * Guesses which existing entry a 404 was looking for, by comparing the last
 * segment of the missing URL with entry slugs. A guess, and labelled one in
 * the response: slugs that look alike are not proof that the content is the
 * same, so a person decides whether to redirect.
 *
 * Split in three because the database sits in the middle: segment() and
 * searchToken() decide what to ask for, a LIKE query fetches a bounded set of
 * candidates, and best() ranks them. Only the middle step needs Craft.
 */
final class SlugSuggester {
    /** Below this, a word matches too many slugs to narrow anything. */
    private const int MIN_WORD_LENGTH = 3;

    /** similar_text percentage a candidate must reach to be worth showing. */
    private const float MIN_SIMILARITY = 60.0;

    /**
     * The last path segment, normalised to the alphabet a Craft slug uses.
     *
     * WHY normalised rather than taken as is: the result feeds a slug query
     * param, where Craft reads `*` as a wildcard and `,` as a list separator
     * (Db::parseParam), so anything outside [a-z0-9-] would change the query.
     */
    public static function segment(string $url): ?string {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        $last = (string) end($segments);
        $last = (string) preg_replace('/\.[a-z0-9]{2,5}$/i', '', rawurldecode($last));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($last)), '-');

        if (strlen($slug) < self::MIN_WORD_LENGTH || preg_match('/[a-z]/', $slug) !== 1) {
            return null;
        }

        return $slug;
    }

    /**
     * The one word the candidate query searches on: the longest, because it
     * is the most specific and so returns the fewest unrelated slugs.
     */
    public static function searchToken(string $segment): ?string {
        $words = array_filter(
            explode('-', $segment),
            static fn (string $word): bool => strlen($word) >= self::MIN_WORD_LENGTH,
        );

        if ($words === []) {
            return null;
        }

        usort($words, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $words[0];
    }

    /**
     * @param list<string> $slugs
     */
    public static function best(string $segment, array $slugs): ?string {
        $best = null;
        $bestScore = self::MIN_SIMILARITY;

        // similar_text reaches 100 only for identical strings, so an exact
        // slug (the page moved) always outranks a near one.
        foreach ($slugs as $slug) {
            similar_text($segment, $slug, $score);
            if ($score > $bestScore) {
                $best = $slug;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
