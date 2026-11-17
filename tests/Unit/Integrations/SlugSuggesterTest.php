<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integrations\retour\SlugSuggester;

describe('SlugSuggester::segment()', function () {
    it('takes the last path segment of a 404 url, lowercased', function () {
        expect(SlugSuggester::segment('/nl/Blog/Summer-Sale-2024'))->toBe('summer-sale-2024');
    });

    it('ignores the query string, trailing slash and a file extension', function () {
        expect(SlugSuggester::segment('/products/red-shoes.html/?utm_source=x'))->toBe('red-shoes');
    });

    it('decodes and folds characters a slug cannot hold into hyphens', function () {
        expect(SlugSuggester::segment('/over%20ons_team'))->toBe('over-ons-team');
    });

    it('has nothing to suggest from a segment without letters or too short to mean anything', function (string $url) {
        expect(SlugSuggester::segment($url))->toBeNull();
    })->with([['/page/2'], ['/archive/2024'], ['/'], [''], ['/ab'], ['/%%%']]);
});

describe('SlugSuggester::searchToken()', function () {
    it('picks the longest word, so the LIKE narrows the most', function () {
        expect(SlugSuggester::searchToken('big-summer-sale-2024'))->toBe('summer');
    });

    it('answers null when no word is long enough to search on', function () {
        expect(SlugSuggester::searchToken('a-b-cd'))->toBeNull();
    });
});

describe('SlugSuggester::best()', function () {
    it('picks the most similar slug among the candidates', function () {
        expect(SlugSuggester::best('summer-sale-2024', ['summer-sale-2025', 'winter-sale', 'summer']))
            ->toBe('summer-sale-2025');
    });

    it('suggests nothing when no candidate is close enough', function () {
        expect(SlugSuggester::best('summer-sale-2024', ['contact', 'about-us']))->toBeNull();
    });

    it('suggests nothing from no candidates', function () {
        expect(SlugSuggester::best('summer-sale', []))->toBeNull();
    });

    it('prefers the exact slug, which means the page moved', function () {
        expect(SlugSuggester::best('red-shoes', ['red-shoe', 'red-shoes', 'red-shoes-sale']))->toBe('red-shoes');
    });
});
