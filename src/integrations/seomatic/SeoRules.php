<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\seomatic;

/**
 * What counts as an SEO gap, judged against the limits SEOmatic itself is
 * configured with (Settings::$maxTitleLength / $maxDescriptionLength), so the
 * answer agrees with the warnings its own control panel shows.
 *
 * Severities: "error" is something missing, "warning" is something SEOmatic
 * will truncate, and "flag" is a deliberate-looking choice (noindex) that is
 * only worth a second look because the entry is live.
 */
final readonly class SeoRules {
    /**
     * SEOmatic's own text when a meta template fails to render
     * (helpers/MetaValue.php, "Error rendering `{template}` -> {error}").
     * Measured as a description it would read as a long one, so it is
     * reported as what it is.
     */
    private const string RENDER_ERROR_PREFIX = 'Error rendering `';

    public function __construct(
        private int $maxTitleLength,
        private int $maxDescriptionLength,
    ) {
    }

    /**
     * @return list<array{issue: string, detail: string, severity: string}>
     */
    public function issues(SeoSnapshot $seo, bool $live): array {
        return array_values(array_filter([
            ...$this->text('title', $seo->title, $this->maxTitleLength, 'maxTitleLength'),
            ...$this->text('description', $seo->description, $this->maxDescriptionLength, 'maxDescriptionLength'),
            $this->ogImage($seo->ogImage),
            $this->noindex($seo->robots, $live),
        ]));
    }

    /**
     * @return list<array{issue: string, detail: string, severity: string}>
     */
    private function text(string $name, string $value, int $max, string $setting): array {
        $value = trim($value);

        if (str_starts_with($value, self::RENDER_ERROR_PREFIX)) {
            return [$this->issue("{$name}_render_error", mb_substr($value, 0, 300), 'error')];
        }

        // A missing title is not reported: SEOmatic falls back to the site
        // name (MetaTitle::prepForRender), so the page always has one.
        if ($value === '' && $name !== 'description') {
            return [];
        }

        if ($value === '') {
            return [$this->issue('missing_description', 'No meta description.', 'error')];
        }

        $length = mb_strlen($value);
        if ($length <= $max) {
            return [];
        }

        $detail = "{$length} characters; SEOmatic {$setting} is {$max}, so it is truncated when rendered.";

        return [$this->issue("{$name}_too_long", $detail, 'warning')];
    }

    /** @return array{issue: string, detail: string, severity: string}|null */
    private function ogImage(string $image): ?array {
        if (trim($image) !== '') {
            return null;
        }

        return $this->issue('missing_og_image', 'No og:image for social sharing.', 'error');
    }

    /** @return array{issue: string, detail: string, severity: string}|null */
    private function noindex(string $robots, bool $live): ?array {
        $directives = array_map(trim(...), explode(',', mb_strtolower($robots)));
        $hidden = array_intersect($directives, ['noindex', 'none']) !== [];

        if (!$live || !$hidden) {
            return null;
        }

        return $this->issue('noindex_on_live_entry', "robots is \"{$robots}\" on a live entry.", 'flag');
    }

    /** @return array{issue: string, detail: string, severity: string} */
    private function issue(string $issue, string $detail, string $severity): array {
        return ['issue' => $issue, 'detail' => $detail, 'severity' => $severity];
    }
}
