<?php

declare(strict_types=1);

use stimmt\craft\Mcp\Tests\Smoke\Shape;

/**
 * The smoke harness is only a guard while its own reducer is stable. A shape
 * that depends on how many rows happened to disagree makes every baseline
 * argue with the next run, and the noise is what stops anyone reading the diff.
 */
describe('smoke Shape', function () {
    // Keys come back sorted, so the same payload always diffs against a
    // baseline in the same order regardless of how the tool built it.
    it('reduces leaves to types but keeps booleans and nulls, which carry the outcome', function () {
        expect(Shape::of(['success' => true, 'count' => 3, 'note' => 'hi', 'gone' => null]))
            ->toBe(['count' => '<number>', 'gone' => null, 'note' => '<string>', 'success' => true]);
    });

    it('reduces a list to one merged element shape', function () {
        expect(Shape::of([['id' => 1], ['id' => 2], ['id' => 3]]))->toBe([['id' => '<number>']]);
    });

    it('marks a key absent from some rows as optional', function () {
        expect(Shape::of([['id' => 1, 'uri' => 'a'], ['id' => 2]]))
            ->toBe([['id' => '<number>', 'uri?' => '<string>']]);
    });

    // The regression: merging is pairwise, so a shape carrying "uri?" came back
    // in and had another "?" appended, leaving the same field in the baseline
    // under "uri?" and "uri??" at once.
    it('marks a key optional exactly once however many rows disagree', function () {
        $shape = Shape::of([
            ['id' => 1, 'uri' => 'a'],
            ['id' => 2],
            ['id' => 3, 'uri' => 'c'],
            ['id' => 4],
            ['id' => 5, 'uri' => 'e'],
        ]);

        expect($shape)->toBe([['id' => '<number>', 'uri?' => '<string>']])
            ->and(array_keys($shape[0]))->not->toContain('uri??');
    });

    it('keeps a key optional once any row has missed it, whatever order the rows arrive in', function () {
        expect(Shape::of([['id' => 1], ['id' => 2, 'uri' => 'b'], ['id' => 3, 'uri' => 'c']]))
            ->toBe([['id' => '<number>', 'uri?' => '<string>']]);
    });

    it('collapses a flag that differs between rows, because that is data', function () {
        expect(Shape::of([['live' => true], ['live' => false]]))->toBe([['live' => '<bool>']]);
    });

    it('flattens a three-way type disagreement instead of nesting it', function () {
        expect(Shape::of([['v' => 1], ['v' => null], ['v' => 'x']]))->toBe([['v' => '<null|number|string>']]);
    });
});
