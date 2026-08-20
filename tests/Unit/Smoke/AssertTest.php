<?php

declare(strict_types=1);

use stimmt\craft\Mcp\Tests\Smoke\Assert;

/**
 * Assert is the only thing in the harness that looks at values rather than
 * shape, so a rule that quietly stopped being able to fail would disable value
 * checking across every profile while every run stayed green. Each keyword is
 * therefore pinned in BOTH directions: the passing case proves it accepts what
 * it should, and the failing case proves it can still say no at all.
 */
describe('smoke Assert', function () {
    it('passes a payload that satisfies every rule', function () {
        expect(Assert::check(
            ['success' => true, 'count' => 3, 'entries' => [1, 2], 'id' => 7, 'name' => 'x'],
            ['success' => true, 'count' => '>=3', 'entries' => 'notEmpty', 'id' => 'isInt', 'name' => 'isString'],
        ))->toBe([]);
    });

    it('reports a missing path rather than treating it as satisfied', function () {
        expect(Assert::check(['a' => 1], ['b' => 'present']))->toBe(['b is missing']);
    });

    it('walks nested and numeric path segments', function () {
        $payload = ['entry' => ['fields' => [['id' => 9]]]];

        expect(Assert::check($payload, ['entry.fields.0.id' => 9]))->toBe([])
            ->and(Assert::check($payload, ['entry.fields.0.id' => 10]))->toHaveCount(1);
    });

    describe('strict equality', function () {
        it('accepts the exact value', function () {
            expect(Assert::check(['success' => true, 'draftId' => null], ['success' => true, 'draftId' => null]))->toBe([]);
        });

        // The regression this guards: success flipping to false is the whole
        // reason the harness looks at values at all.
        it('rejects a different value, and is not fooled by a falsy lookalike', function () {
            expect(Assert::check(['success' => false], ['success' => true]))->toHaveCount(1)
                ->and(Assert::check(['success' => 0], ['success' => true]))->toHaveCount(1)
                ->and(Assert::check(['success' => null], ['success' => false]))->toHaveCount(1);
        });
    });

    describe('the >=N floor', function () {
        it('accepts a number at or above the floor and counts an array by its size', function () {
            expect(Assert::check(['n' => 5, 'list' => [1, 2, 3]], ['n' => '>=5', 'list' => '>=3']))->toBe([]);
        });

        it('rejects a number below the floor and a list that is too short', function () {
            expect(Assert::check(['n' => 4], ['n' => '>=5']))->toHaveCount(1)
                ->and(Assert::check(['list' => [1]], ['list' => '>=2']))->toHaveCount(1);
        });

        it('rejects a value it cannot compare at all', function () {
            expect(Assert::check(['n' => 'lots'], ['n' => '>=1']))->toHaveCount(1);
        });
    });

    describe('notEmpty', function () {
        it('accepts a filled list, a filled string and a non-null scalar', function () {
            expect(Assert::check(['a' => [1], 'b' => 'x', 'c' => 0], ['a' => 'notEmpty', 'b' => 'notEmpty', 'c' => 'notEmpty']))
                ->toBe([]);
        });

        // An empty list is the quiet failure shape diffing cannot see: a
        // list_entries returning nothing has exactly yesterday's shape.
        it('rejects an empty list, a blank string and null', function () {
            expect(Assert::check(['a' => []], ['a' => 'notEmpty']))->toHaveCount(1)
                ->and(Assert::check(['a' => '   '], ['a' => 'notEmpty']))->toHaveCount(1)
                ->and(Assert::check(['a' => null], ['a' => 'notEmpty']))->toHaveCount(1);
        });
    });

    describe('type keywords', function () {
        it('accept the matching type', function () {
            expect(Assert::check(['i' => 1, 's' => 'x', 'a' => []], ['i' => 'isInt', 's' => 'isString', 'a' => 'isArray']))
                ->toBe([]);
        });

        it('reject a near miss, including a numeric string for isInt', function () {
            expect(Assert::check(['i' => '1'], ['i' => 'isInt']))->toHaveCount(1)
                ->and(Assert::check(['i' => 1.5], ['i' => 'isInt']))->toHaveCount(1)
                ->and(Assert::check(['s' => 5], ['s' => 'isString']))->toHaveCount(1)
                ->and(Assert::check(['a' => 'x'], ['a' => 'isArray']))->toHaveCount(1);
        });
    });

    it('collects one line per broken rule rather than stopping at the first', function () {
        expect(Assert::check(['a' => 1, 'b' => 2], ['a' => 2, 'b' => 3, 'c' => 'present']))->toHaveCount(3);
    });
});
