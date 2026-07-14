<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\query\Buckets;

describe('Buckets::parseGroupBy', function () {
    it('recognizes attribute targets', function (string $input) {
        expect(Buckets::parseGroupBy($input))
            ->toBe(['kind' => 'attribute', 'target' => $input, 'granularity' => null]);
    })->with([['status'], ['type'], ['section'], ['site'], ['author']]);

    it('parses date buckets', function () {
        expect(Buckets::parseGroupBy('month:dateUpdated'))
            ->toBe(['kind' => 'date', 'target' => 'dateUpdated', 'granularity' => 'month']);
    });

    it('rejects unknown date attributes and granularities', function (string $input) {
        Buckets::parseGroupBy($input);
    })->with([['month:expiryDate'], ['hour:dateUpdated']])
        ->throws(InvalidArgumentException::class);

    it('treats anything else as a field handle', function () {
        expect(Buckets::parseGroupBy('vehicleType'))
            ->toBe(['kind' => 'field', 'target' => 'vehicleType', 'granularity' => null]);
    });
});

describe('Buckets::dateKey', function () {
    $date = new DateTimeImmutable('2026-07-14 10:30:00');

    it('formats each granularity', function (string $granularity, string $expected) use ($date) {
        expect(Buckets::dateKey($date, $granularity))->toBe($expected);
    })->with([
        ['day', '2026-07-14'],
        ['week', '2026-W29'],
        ['month', '2026-07'],
        ['year', '2026'],
    ]);

    it('buckets null dates as empty', function () {
        expect(Buckets::dateKey(null, 'month'))->toBe('(empty)');
    });
});

describe('Buckets eager loading', function () {
    it('eager-loads relation fields across all statuses', function () {
        $source = (string) file_get_contents((new ReflectionClass(Buckets::class))->getFileName());

        expect($source)->toContain("['status' => null]")
            ->and($source)->toContain('EagerLoadingFieldInterface');
    });
});
