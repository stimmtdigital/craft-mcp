<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integrations\seomatic\SeoRules;
use stimmt\craft\Mcp\integrations\seomatic\SeoSnapshot;

$healthy = static fn (array $overrides = []): SeoSnapshot => new SeoSnapshot(...[
    'title' => 'A fine title',
    'description' => 'A description that says what the page is about.',
    'ogImage' => 'https://example.com/og.jpg',
    'robots' => 'all',
    ...$overrides,
]);

$issueNames = static fn (array $issues): array => array_column($issues, 'issue');

describe('SeoRules::issues()', function () use ($healthy, $issueNames) {
    it('finds nothing wrong with a complete, live entry', function () use ($healthy) {
        expect((new SeoRules(70, 155))->issues($healthy(), true))->toBe([]);
    });

    it('reports a missing or blank description', function (string $empty) use ($healthy, $issueNames) {
        $issues = (new SeoRules(70, 155))->issues($healthy(['description' => $empty]), true);

        expect($issueNames($issues))->toBe(['missing_description'])
            ->and($issues[0]['severity'])->toBe('error');
    })->with([[''], ["  \n "]]);

    it('reports a description over the configured maximum, with both lengths', function () use ($healthy) {
        $issues = (new SeoRules(70, 20))->issues($healthy(['description' => str_repeat('é', 21)]), true);

        expect($issues)->toBe([[
            'issue' => 'description_too_long',
            'detail' => '21 characters; SEOmatic maxDescriptionLength is 20, so it is truncated when rendered.',
            'severity' => 'warning',
        ]]);
    });

    it('accepts a description exactly at the maximum', function () use ($healthy) {
        expect((new SeoRules(70, 20))->issues($healthy(['description' => str_repeat('a', 20)]), true))->toBe([]);
    });

    it('reports a title over the configured maximum', function () use ($healthy, $issueNames) {
        $issues = (new SeoRules(10, 155))->issues($healthy(['title' => 'Eleven char']), true);

        expect($issueNames($issues))->toBe(['title_too_long']);
    });

    it('reports a missing social image', function () use ($healthy, $issueNames) {
        expect($issueNames((new SeoRules(70, 155))->issues($healthy(['ogImage' => '']), true)))
            ->toBe(['missing_og_image']);
    });

    it('flags noindex on a live entry, as a flag rather than an error', function (string $robots) use ($healthy) {
        $issues = (new SeoRules(70, 155))->issues($healthy(['robots' => $robots]), true);

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['issue'])->toBe('noindex_on_live_entry')
            ->and($issues[0]['severity'])->toBe('flag');
    })->with([['noindex, nofollow'], ['none'], ['NOINDEX']]);

    it('does not flag noindex on an entry that is not live', function () use ($healthy) {
        expect((new SeoRules(70, 155))->issues($healthy(['robots' => 'noindex']), false))->toBe([]);
    });

    it('does not mistake a robots value that merely contains "none" as a word part', function () use ($healthy) {
        expect((new SeoRules(70, 155))->issues($healthy(['robots' => 'all, max-snippet:nonexistent']), true))->toBe([]);
    });

    it('reports a value SEOmatic could not render instead of measuring the error text', function () use ($healthy) {
        $issues = (new SeoRules(70, 20))->issues(
            $healthy(['description' => 'Error rendering `{{ entry.summary }}` -> Variable "summary" does not exist.']),
            true,
        );

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['issue'])->toBe('description_render_error');
    });
});
