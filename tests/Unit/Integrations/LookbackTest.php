<?php

declare(strict_types=1);

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\integrations\Lookback;

describe('Lookback::since()', function () {
    it('counts back the given number of whole days from now', function () {
        $now = new DateTimeImmutable('2026-11-17 12:00:00', new DateTimeZone('UTC'));

        expect(Lookback::since(30, $now)->format('Y-m-d H:i:s'))->toBe('2026-10-18 12:00:00');
    });

    it('refuses a window that asks for nothing or for the past before time', function (int $days) {
        expect(fn () => Lookback::since($days, new DateTimeImmutable()))
            ->toThrow(ToolCallException::class, "days must be between 1 and 3650, got {$days}");
    })->with([[0], [-3], [3651]]);
});

describe('Lookback::utc()', function () {
    it('turns a database UTC timestamp into ISO 8601 with its zone stated', function () {
        expect(Lookback::utc('2026-11-01 08:15:00'))->toBe('2026-11-01T08:15:00+00:00');
    });

    it('answers null for no timestamp or one it cannot read', function (?string $value) {
        expect(Lookback::utc($value))->toBeNull();
    })->with([[null], [''], ['yesterday-ish']]);
});
