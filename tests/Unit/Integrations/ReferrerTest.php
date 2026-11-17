<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integrations\retour\Referrer;

describe('Referrer::withoutQuery()', function () {
    it('drops the query string, which can carry tokens and search terms', function () {
        expect(Referrer::withoutQuery('https://example.com/search?q=jane%40example.com&token=abc'))
            ->toBe('https://example.com/search');
    });

    it('drops the fragment as well', function () {
        expect(Referrer::withoutQuery('https://example.com/page#section?x=1'))
            ->toBe('https://example.com/page');
    });

    it('keeps a referrer that has neither', function () {
        expect(Referrer::withoutQuery('https://example.com/blog/post'))->toBe('https://example.com/blog/post');
    });

    it('answers null for no referrer, rather than an empty string', function (string $empty) {
        expect(Referrer::withoutQuery($empty))->toBeNull();
    })->with([[''], ['   '], ['?only=query']]);
});
