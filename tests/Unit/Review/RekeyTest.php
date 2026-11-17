<?php

declare(strict_types=1);

use stimmt\craft\Mcp\review\FieldChanges;
use stimmt\craft\Mcp\review\Rekey;

/**
 * A draft that edits a Matrix block holds its own copy of it under a new id
 * whose canonicalId is the live block. Rekey maps those ids back so the diff
 * reads "this block changed", not "one removed, one added".
 */
describe('Rekey::fields()', function () {
    $block = static fn (string $copy, int $position, array $fields = []): array => [
        'type' => 'text', 'fields' => ['copy' => $copy, ...$fields], 'position' => $position,
    ];

    it('renames a draft block copy to its canonical id', function () use ($block) {
        $fields = Rekey::fields(['body' => [501 => $block('Hi', 1), 11 => $block('B', 2)]], [501 => 10]);

        expect(array_keys($fields['body']))->toBe([10, 11]);
    });

    it('renames blocks nested in blocks and in a ContentBlock', function () use ($block) {
        $fields = Rekey::fields([
            'body' => [1 => $block('A', 1, ['inner' => [700 => $block('x', 1)]])],
            'seo' => ['fields' => ['cards' => [800 => $block('y', 1)]]],
        ], [700 => 70, 800 => 80]);

        expect(array_keys($fields['body'][1]['fields']['inner']))->toBe([70])
            ->and(array_keys($fields['seo']['fields']['cards']))->toBe([80]);
    });

    it('leaves non-block values alone', function () {
        $fields = ['title' => 'x', 'related' => [['section' => 'a', 'slug' => 'b']]];

        expect(Rekey::fields($fields, [1 => 2]))->toBe($fields);
    });

    it('turns an edited block copy into a changed block in the diff', function () use ($block) {
        $live = ['body' => [10 => $block('Hello', 1)]];
        $draft = ['body' => [501 => $block('Hi', 1)]];

        $blocks = (new FieldChanges())->between($live, Rekey::fields($draft, [501 => 10]))['changes'][0]['blocks'];

        expect(array_column($blocks, 'kind', 'block'))->toBe(['10' => 'changed']);
    });
});
