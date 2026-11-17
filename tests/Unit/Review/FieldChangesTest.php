<?php

declare(strict_types=1);

use stimmt\craft\Mcp\review\Clip;
use stimmt\craft\Mcp\review\FieldChanges;

/**
 * stimmt_drafts_diff's comparison of two Reader field maps, on hand-built
 * payloads in the Reader's own shapes: scalars, natural-key relations,
 * Matrix blocks keyed by id with a position, and a ContentBlock's {fields}.
 */
describe('FieldChanges::between()', function () {
    it('reports a changed scalar with its label, before and after', function () {
        $result = (new FieldChanges())->between(['summary' => 'Old'], ['summary' => 'New'], ['summary' => 'Summary']);

        expect($result['changes'])->toBe([
            ['field' => 'summary', 'label' => 'Summary', 'kind' => 'changed', 'before' => 'Old', 'after' => 'New'],
        ])->and($result['unchangedCount'])->toBe(0);
    });

    it('counts identical values as unchanged instead of listing them', function () {
        $result = (new FieldChanges())->between(
            ['a' => 'same', 'b' => [['section' => 'news', 'slug' => 'x']], 'c' => null],
            ['a' => 'same', 'b' => [['section' => 'news', 'slug' => 'x']], 'c' => ''],
        );

        expect($result['changes'])->toBe([])
            ->and($result['unchangedCount'])->toBe(3);
    });

    // Object key order carries no meaning in a JSON payload; a reordered
    // natural key is the same relation.
    it('ignores the key order of an object value', function () {
        $result = (new FieldChanges())->between(
            ['link' => ['type' => 'url', 'value' => 'https://a']],
            ['link' => ['value' => 'https://a', 'type' => 'url']],
        );

        expect($result['changes'])->toBe([]);
    });

    it('treats a reordered relation list as a change', function () {
        $first = ['section' => 'news', 'slug' => 'a'];
        $second = ['section' => 'news', 'slug' => 'b'];
        $result = (new FieldChanges())->between(['related' => [$first, $second]], ['related' => [$second, $first]]);

        expect($result['changes'][0]['kind'])->toBe('changed');
    });

    it('calls a value that appears "added" and one that empties "removed"', function () {
        $result = (new FieldChanges())->between(['a' => null, 'b' => 'gone'], ['a' => 'new', 'b' => '']);

        expect(array_column($result['changes'], 'kind', 'field'))->toBe(['a' => 'added', 'b' => 'removed']);
    });

    // A lightswitch turned off is a value, not an absence.
    it('treats false as a value, not as empty', function () {
        $result = (new FieldChanges())->between(['flag' => true], ['flag' => false]);

        expect($result['changes'][0]['kind'])->toBe('changed');
    });

    it('cuts long text and flags the pair as truncated', function () {
        $long = str_repeat('é', Clip::MAX_CHARS + 20);
        $change = (new FieldChanges())->between(['body' => 'short'], ['body' => $long])['changes'][0];

        expect(mb_strlen($change['after']))->toBe(Clip::MAX_CHARS)
            ->and($change['before'])->toBe('short')
            ->and($change['truncated'])->toBeTrue();
    });

    it('carries no truncated key when nothing was cut', function () {
        $change = (new FieldChanges())->between(['body' => 'a'], ['body' => 'b'])['changes'][0];

        expect($change)->not->toHaveKey('truncated');
    });

    // newEntry: a never-published draft has nothing before it, so every
    // non-empty value is added and the empty ones are not news.
    it('lists every non-empty value as added against an empty before', function () {
        $result = (new FieldChanges())->between([], ['a' => 'x', 'b' => null, 'c' => []]);

        expect($result['changes'])->toBe([['field' => 'a', 'label' => 'a', 'kind' => 'added', 'before' => null, 'after' => 'x']])
            ->and($result['unchangedCount'])->toBe(2);
    });

    it('orders the output by the labels first, then by the payload', function () {
        $result = (new FieldChanges())->between(['z' => 1, 'a' => 1], ['z' => 2, 'a' => 2], ['a' => 'A']);

        expect(array_column($result['changes'], 'field'))->toBe(['a', 'z']);
    });

    it('reports a ContentBlock value by its own fields', function () {
        $change = (new FieldChanges())->between(
            ['seo' => ['fields' => ['metaTitle' => 'Old', 'noindex' => false]]],
            ['seo' => ['fields' => ['metaTitle' => 'New', 'noindex' => false]]],
        )['changes'][0];

        expect($change)->toBe(['field' => 'seo', 'label' => 'seo', 'kind' => 'changed', 'changes' => [
            ['field' => 'metaTitle', 'label' => 'metaTitle', 'kind' => 'changed', 'before' => 'Old', 'after' => 'New'],
        ]]);
    });
});

describe('Matrix values', function () {
    $block = static fn (string $type, array $fields, int $position, array $extra = []): array => [
        'title' => null, 'slug' => null, 'type' => $type, 'enabled' => true, 'collapsed' => false,
        'fields' => $fields, 'position' => $position, ...$extra,
    ];

    it('reports per block rather than as one value', function () use ($block) {
        $change = (new FieldChanges())->between(
            ['body' => [10 => $block('text', ['copy' => 'Hello'], 1), 11 => $block('quote', ['quote' => 'Q'], 2)]],
            ['body' => [10 => $block('text', ['copy' => 'Hi'], 1), 11 => $block('quote', ['quote' => 'Q'], 2)]],
        )['changes'][0];

        expect($change)->not->toHaveKey('before')
            ->and($change['blocks'])->toBe([[
                'block' => '10', 'type' => 'text', 'kind' => 'changed',
                'changes' => [['field' => 'copy', 'label' => 'copy', 'kind' => 'changed', 'before' => 'Hello', 'after' => 'Hi']],
            ]]);
    });

    it('lists an added block with its position and its fields', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [10 => $block('text', ['copy' => 'A'], 1)]],
            ['body' => [10 => $block('text', ['copy' => 'A'], 1), 12 => $block('quote', ['quote' => 'Q', 'by' => null], 2)]],
        )['changes'][0]['blocks'];

        expect($blocks)->toBe([[
            'block' => '12', 'type' => 'quote', 'kind' => 'added', 'position' => 2,
            'changes' => [['field' => 'quote', 'label' => 'quote', 'kind' => 'added', 'before' => null, 'after' => 'Q']],
        ]]);
    });

    it('lists a removed block with its old position', function () use ($block) {
        $change = (new FieldChanges())->between(
            ['body' => [10 => $block('text', ['copy' => 'A'], 1), 11 => $block('quote', ['quote' => 'Q'], 2)]],
            ['body' => [10 => $block('text', ['copy' => 'A'], 1)]],
        )['changes'][0];

        expect($change['kind'])->toBe('changed')
            ->and($change['blocks'][0])->toMatchArray(['block' => '11', 'kind' => 'removed', 'position' => 2]);
    });

    it('calls a field whose every block was removed "removed"', function () use ($block) {
        $change = (new FieldChanges())->between(['body' => [10 => $block('text', ['copy' => 'A'], 1)]], ['body' => []])['changes'][0];

        expect($change['kind'])->toBe('removed')
            ->and($change['blocks'][0]['kind'])->toBe('removed');
    });

    // Dragging the last block to the top shifts every position number; only
    // the dragged block moved.
    it('reports only the block that was dragged as moved', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [1 => $block('a', [], 1), 2 => $block('a', [], 2), 3 => $block('a', [], 3)]],
            ['body' => [3 => $block('a', [], 1), 1 => $block('a', [], 2), 2 => $block('a', [], 3)]],
        )['changes'][0]['blocks'];

        expect($blocks)->toBe([['block' => '3', 'type' => 'a', 'kind' => 'moved', 'from' => 3, 'to' => 1]]);
    });

    it('does not call blocks shifted by an insertion moved', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [1 => $block('a', [], 1), 2 => $block('a', [], 2)]],
            ['body' => [9 => $block('a', ['x' => 'new'], 1), 1 => $block('a', [], 2), 2 => $block('a', [], 3)]],
        )['changes'][0]['blocks'];

        expect(array_column($blocks, 'kind', 'block'))->toBe(['9' => 'added']);
    });

    it('reports a block that moved and changed as changed, with from and to', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [1 => $block('a', ['x' => '1'], 1), 2 => $block('a', [], 2)]],
            ['body' => [2 => $block('a', [], 1), 1 => $block('a', ['x' => '2'], 2)]],
        )['changes'][0]['blocks'];

        expect($blocks)->toHaveCount(1)
            ->and($blocks[0])->toMatchArray(['block' => '1', 'kind' => 'changed', 'from' => 1, 'to' => 2]);
    });

    it('reports a disabled block as a change to its enabled attribute', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [1 => $block('a', [], 1)]],
            ['body' => [1 => $block('a', [], 1, ['enabled' => false])]],
        )['changes'][0]['blocks'];

        expect($blocks[0]['changes'])->toBe([['field' => 'enabled', 'label' => 'enabled', 'kind' => 'changed', 'before' => true, 'after' => false]]);
    });

    // Collapsing a block in the control panel is not an edit.
    it('ignores a block being collapsed', function () use ($block) {
        $result = (new FieldChanges())->between(
            ['body' => [1 => $block('a', [], 1)]],
            ['body' => [1 => $block('a', [], 1, ['collapsed' => true])]],
        );

        expect($result['changes'])->toBe([])
            ->and($result['unchangedCount'])->toBe(1);
    });

    it('leaves the collapsed state out of a block that did change', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [1 => $block('a', ['x' => '1'], 1)]],
            ['body' => [1 => $block('a', ['x' => '2'], 1, ['collapsed' => true])]],
        )['changes'][0]['blocks'];

        expect(array_column($blocks[0]['changes'], 'field'))->toBe(['x']);
    });

    it('descends into a Matrix nested in a block', function () use ($block) {
        $blocks = (new FieldChanges())->between(
            ['body' => [1 => $block('cols', ['inner' => [5 => $block('text', ['copy' => 'A'], 1)]], 1)]],
            ['body' => [1 => $block('cols', ['inner' => [5 => $block('text', ['copy' => 'B'], 1)]], 1)]],
        )['changes'][0]['blocks'];

        expect($blocks[0]['changes'][0]['blocks'][0]['changes'][0])
            ->toBe(['field' => 'copy', 'label' => 'copy', 'kind' => 'changed', 'before' => 'A', 'after' => 'B']);
    });
});
