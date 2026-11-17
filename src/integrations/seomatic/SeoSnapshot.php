<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\seomatic;

/**
 * The effective SEO values of one entry, already resolved by SEOmatic, as
 * plain strings so the rules that judge them need neither Craft nor SEOmatic.
 */
final readonly class SeoSnapshot {
    public function __construct(
        public string $title,
        public string $description,
        public string $ogImage,
        public string $robots,
    ) {
    }
}
