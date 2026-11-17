<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\retour;

use craft\elements\Entry;
use stimmt\craft\Mcp\support\Authorization;

/**
 * The live entry a 404 most likely meant, found by slug similarity on the
 * same site. Bounded twice: one LIKE query per URL, capped at CANDIDATES rows,
 * and scoped to the sections the acting user may view, so a suggestion never
 * names an entry the caller could not have listed.
 */
final class EntrySuggestions {
    private const int CANDIDATES = 25;

    /**
     * @return array{entryId: int, title: ?string, url: ?string, basis: string}|null
     */
    public function forUrl(string $url, int $siteId): ?array {
        $segment = SlugSuggester::segment($url);
        $token = $segment === null ? null : SlugSuggester::searchToken($segment);

        if ($segment === null || $token === null) {
            return null;
        }

        $query = Entry::find()
            ->siteId($siteId)
            ->slug("*{$token}*")
            ->uri(':notempty:')
            ->limit(self::CANDIDATES);
        Authorization::scopeQuery($query);

        $entries = [];
        foreach ($query->all() as $entry) {
            $entries[(string) $entry->slug] = $entry;
        }

        $slug = SlugSuggester::best($segment, array_map(strval(...), array_keys($entries)));
        if ($slug === null) {
            return null;
        }

        return [
            'entryId' => (int) $entries[$slug]->id,
            'title' => $entries[$slug]->title,
            'url' => $entries[$slug]->getUrl(),
            'basis' => "Suggestion only: slug '{$slug}' resembles '{$segment}'. Check the content before redirecting.",
        ];
    }
}
