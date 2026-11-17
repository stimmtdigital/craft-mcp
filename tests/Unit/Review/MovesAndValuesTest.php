<?php

declare(strict_types=1);

use stimmt\craft\Mcp\review\Clip;
use stimmt\craft\Mcp\review\Moves;
use stimmt\craft\Mcp\review\Values;

describe('Moves::between()', function () {
    it('finds nothing moved in an unchanged order', function () {
        expect(Moves::between(['1', '2', '3'], ['1', '2', '3']))->toBe([]);
    });

    it('finds the one block taken out of a run that kept its order', function () {
        expect(Moves::between(['1', '2', '3', '4'], ['1', '3', '4', '2']))->toBe(['2']);
    });

    it('finds the smallest set of moves for a swap', function () {
        expect(Moves::between(['1', '2'], ['2', '1']))->toHaveCount(1);
    });
});

describe('Values', function () {
    it('tells a Matrix block list from a ContentBlock value and from a relation list', function () {
        expect(Values::isBlockList([5 => ['type' => 't', 'fields' => []]]))->toBeTrue()
            ->and(Values::isBlockList(['fields' => ['a' => 1]]))->toBeFalse()
            ->and(Values::isBlockList([['section' => 'a', 'slug' => 'b']]))->toBeFalse()
            ->and(Values::isBlockList([]))->toBeFalse()
            ->and(Values::isBlock(['fields' => ['a' => 1]]))->toBeTrue();
    });

    it('treats null, empty string and empty list as empty, but not false or 0', function () {
        expect(array_map(Values::isEmpty(...), [null, '', [], false, 0, '0']))
            ->toBe([true, true, true, false, false, false]);
    });

    it('orders blocks by position, then by arrival', function () {
        expect(Values::blockOrder([7 => ['fields' => [], 'position' => 2], 3 => ['fields' => [], 'position' => 1], 9 => ['fields' => []]]))
            ->toBe(['3', '7', '9']);
    });

    it('sees a block list reordered as a different value', function () {
        $a = [1 => ['fields' => [], 'position' => 1], 2 => ['fields' => [], 'position' => 2]];
        $b = [1 => ['fields' => [], 'position' => 2], 2 => ['fields' => [], 'position' => 1]];

        expect(Values::same($a, $b))->toBeFalse();
    });
});

describe('Clip::of()', function () {
    it('leaves short text and non-text alone', function (mixed $value) {
        expect(Clip::of($value))->toBe(['value' => $value, 'truncated' => false]);
    })->with([['short'], [str_repeat('a', Clip::MAX_CHARS)], [[str_repeat('a', 900)]], [42]]);

    it('cuts text over the limit by characters, not bytes', function () {
        $clipped = Clip::of(str_repeat('ü', Clip::MAX_CHARS + 1));

        expect(mb_strlen($clipped['value']))->toBe(Clip::MAX_CHARS)
            ->and(mb_check_encoding($clipped['value'], 'UTF-8'))->toBeTrue()
            ->and($clipped['truncated'])->toBeTrue();
    });
});
