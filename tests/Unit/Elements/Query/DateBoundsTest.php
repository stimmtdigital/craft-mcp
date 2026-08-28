<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\InvalidInput;
use stimmt\craft\Mcp\elements\query\Filters;

describe('Filters date bounds', function () {
    it('passes a bound Craft can parse through untouched', function (string $value) {
        expect(Filters::dateParam($value, null))->toBe(['and', ">= {$value}"])
            ->and(Filters::dateParam(null, $value))->toBe(['and', "< {$value}"]);
    })->with([
        ['2026-07-01'],
        ['2026-1-5'],
        ['2026-01-31 14:00'],
        ['2026-01-31T14:00:00Z'],
        ['now'],
        ['tomorrow'],
        // Ambiguous but real: Craft reads this as 31 January, and so does the guard.
        ['31-01-2026'],
        // Craft reads a bare number as a unix timestamp.
        ['1750000000'],
    ]);

    // Left to the database these became an empty datetime (a raw SQL error
    // carrying the whole statement) or a confident count about a month nobody
    // asked about.
    it('refuses a value that names no real instant', function (string $value) {
        Filters::dateParam($value, null);
    })->with([
        ['not-a-date'],
        ['2026-13-45'],
        ['2026-02-30'],
        ['2026-01-31 99:99'],
        // The blank spellings, which disagreed underneath: strtotime refuses
        // '' and reads ' ' as NOW, so a whitespace bound was silently coerced
        // to the moment of the call. createdAfter: " " then answered "0
        // entries" about a section holding sixty-one, and a caller has no
        // reason to doubt a zero.
        [''],
        [' '],
        ['   '],
        ["\t"],
        ["\n"],
    ])->throws(InvalidArgumentException::class);

    it('refuses a blank bound in the same words as any other non-date', function (string $value) {
        try {
            Filters::dateParam(null, $value);
        } catch (InvalidInput $e) {
            expect($e->getMessage())->toContain('in a date filter')->toContain('createdBefore');

            return;
        }

        $this->fail('Expected an InvalidInput for a blank date bound');
    })->with([[''], [' ']]);

    // The tool boundary renders a caller's mistake as written and everything
    // else with a class name and a file and line, and it tells the two apart
    // by type. A bare InvalidArgumentException here reached an agent as
    // "InvalidArgumentException: ... (Filters.php:119)".
    it('refuses with the type the error boundary reads as a caller mistake', function () {
        expect(fn () => Filters::dateParam('not-a-date', null))->toThrow(InvalidInput::class);
    });

    it('refuses an unparsable upper bound as well as a lower one', function () {
        Filters::dateParam('2026-07-01', 'not-a-date');
    })->throws(InvalidArgumentException::class);

    it('names the bad value and a shape that works', function () {
        try {
            Filters::dateParam('not-a-date', null);
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())
                ->toContain('not-a-date')
                ->toContain('createdAfter')
                ->toContain('2026-01-31');

            return;
        }

        $this->fail('Expected an InvalidArgumentException for an unparsable date bound');
    });

    it('still answers null when no bound is given', function () {
        expect(Filters::dateParam(null, null))->toBeNull();
    });
});
