<?php

declare(strict_types=1);

use stimmt\craft\Mcp\review\TranslationGap;

/**
 * stimmt_entries_untranslated's decision per value: is the target site's copy
 * still untranslated, and why.
 */
describe('TranslationGap::reason()', function () {
    it('calls a value the target leaves empty "empty"', function (mixed $target) {
        expect(TranslationGap::reason('Hello', $target))->toBe('empty');
    })->with([[null], [''], ['   '], [[]]]);

    it('calls a value the target holds unchanged "same-as-source"', function () {
        expect(TranslationGap::reason('Hello', 'Hello'))->toBe('same-as-source');
    });

    it('is no gap once the target says something else', function () {
        expect(TranslationGap::reason('Hello', 'Hallo'))->toBeNull();
    });

    // Nothing on the source means nothing to translate yet.
    it('ignores a value that is empty on the source too', function (mixed $source) {
        expect(TranslationGap::reason($source, null))->toBeNull()
            ->and(TranslationGap::reason($source, 'x'))->toBeNull();
    })->with([[null], [''], [' '], [[]]]);

    it('compares text-like values as strings', function () {
        expect(TranslationGap::reason(3, '3'))->toBe('same-as-source');
    });

    it('compares relations by natural key', function () {
        $key = ['section' => 'news', 'slug' => 'launch'];

        expect(TranslationGap::reason([$key], [['slug' => 'launch', 'section' => 'news']]))->toBe('same-as-source')
            ->and(TranslationGap::reason([$key], [['section' => 'news', 'slug' => 'lancering']]))->toBeNull();
    });

    // Each site of a per-site Matrix field holds blocks of its own, so a
    // straight copy has different ids and still says the same thing.
    it('compares Matrix blocks by type and content, never by id', function () {
        $source = [10 => ['type' => 'text', 'slug' => 'a', 'fields' => ['copy' => 'Hello'], 'position' => 1]];
        $copy = [20 => ['type' => 'text', 'slug' => 'b', 'fields' => ['copy' => 'Hello'], 'position' => 1]];
        $translated = [20 => ['type' => 'text', 'slug' => 'b', 'fields' => ['copy' => 'Hallo'], 'position' => 1]];

        expect(TranslationGap::reason($source, $copy))->toBe('same-as-source')
            ->and(TranslationGap::reason($source, $translated))->toBeNull();
    });

    it('reads Matrix blocks in field order, not key order', function () {
        $source = [
            1 => ['type' => 'text', 'fields' => ['copy' => 'A'], 'position' => 1],
            2 => ['type' => 'text', 'fields' => ['copy' => 'B'], 'position' => 2],
        ];
        $reordered = [
            8 => ['type' => 'text', 'fields' => ['copy' => 'B'], 'position' => 2],
            9 => ['type' => 'text', 'fields' => ['copy' => 'A'], 'position' => 1],
        ];

        expect(TranslationGap::reason($source, $reordered))->toBe('same-as-source');
    });
});

describe('TranslationGap::ofEntry()', function () {
    it('lists only the handles it was given, with a reason each', function () {
        $gaps = TranslationGap::ofEntry(
            ['title' => 'Hello', 'intro' => 'Intro', 'body' => 'Body', 'shared' => 'x'],
            ['title' => 'Hello', 'intro' => '', 'body' => 'Lichaam', 'shared' => 'x'],
            ['title', 'intro', 'body'],
        );

        expect($gaps)->toBe([
            ['field' => 'title', 'reason' => 'same-as-source'],
            ['field' => 'intro', 'reason' => 'empty'],
        ]);
    });

    it('treats a handle missing from the target as empty there', function () {
        expect(TranslationGap::ofEntry(['intro' => 'Intro'], [], ['intro']))
            ->toBe([['field' => 'intro', 'reason' => 'empty']]);
    });
});
