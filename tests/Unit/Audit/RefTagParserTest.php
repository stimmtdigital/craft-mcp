<?php

declare(strict_types=1);

use stimmt\craft\Mcp\audit\RefTag;
use stimmt\craft\Mcp\audit\RefTagParser;

/** Each tag as a plain array, so a failure shows every part that differs. */
$parts = static fn (string $text): array => array_map(
    static fn (RefTag $tag): array => [$tag->handle, $tag->ref, $tag->site, $tag->attribute, $tag->id()],
    RefTagParser::parse($text),
);

describe('RefTagParser', function () use ($parts) {
    it('reads every part Craft\'s ref tag grammar allows', function (string $text, array $expected) use ($parts) {
        expect($parts($text))->toBe([$expected]);
    })->with([
        'bare id' => ['{entry:123}', ['entry', '123', null, null, 123]],
        'id, site and attribute' => ['{entry:123@2:url}', ['entry', '123', '2', 'url', 123]],
        'attribute and fallback' => ['{asset:45:url||fallback}', ['asset', '45', null, 'url', 45]],
        'spaced fallback' => ['{asset:45:url || https://example.com/a.jpg}', ['asset', '45', null, 'url', 45]],
        'site by handle' => ['{category:7@english}', ['category', '7', 'english', null, 7]],
        'site by uuid' => [
            '{entry:9@6a1b2c3d-1111-4222-8333-444455556666:url}',
            ['entry', '9', '6a1b2c3d-1111-4222-8333-444455556666', 'url', 9],
        ],
        'class name for the handle' => ['{craft\\elements\\Entry:5}', ['craft\\elements\\Entry', '5', null, null, 5]],
    ]);

    // Craft resolves a non-numeric ref by slug or section/slug, and a UUID is
    // just another non-numeric ref to it. The parser keeps them; id() says
    // they are not ids, which the audit counts as unchecked.
    it('keeps a ref that is not an id, without an id', function (string $text, string $ref) use ($parts) {
        expect($parts($text))->toBe([['entry', $ref, null, null, null]]);
    })->with([
        'section and slug' => ['{entry:news/launch}', 'news/launch'],
        'uuid' => ['{entry:6a1b2c3d-1111-4222-8333-444455556666}', '6a1b2c3d-1111-4222-8333-444455556666'],
        'zero' => ['{entry:0}', '0'],
        'a decimal is_numeric would accept' => ['{entry:1.5}', '1.5'],
    ]);

    it('finds every tag in a piece of rich text, in order', function () use ($parts) {
        $html = '<p><a href="{entry:12@1:url||/about}">About</a> <img src="{asset:45:url}"></p>';

        expect(array_column($parts($html), 4))->toBe([12, 45]);
    });

    it('finds nothing in text that is not a tag', function (string $text) {
        expect(RefTagParser::parse($text))->toBe([]);
    })->with([
        'no braces' => ['entry:12'],
        'empty ref' => ['{entry:}'],
        'unclosed' => ['{entry:12'],
        'no handle' => ['{:12}'],
        'no ref at all' => ['{entry}'],
        'a JSON object' => ['{"value":"x","type":"url"}'],
        'empty string' => [''],
    ]);
});
