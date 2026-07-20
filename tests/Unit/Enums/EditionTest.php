<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\Edition;

describe('Edition', function () {
    it('orders standard below pro', function () {
        expect(Edition::ordered())->toBe(['standard', 'pro']);
    });

    it('compares editions with atLeast', function () {
        expect(Edition::Pro->atLeast(Edition::Standard))->toBeTrue()
            ->and(Edition::Pro->atLeast(Edition::Pro))->toBeTrue()
            ->and(Edition::Standard->atLeast(Edition::Standard))->toBeTrue()
            ->and(Edition::Standard->atLeast(Edition::Pro))->toBeFalse();
    });

    it('resolves stored handles and falls back to Standard', function () {
        expect(Edition::fromHandle('pro'))->toBe(Edition::Pro)
            ->and(Edition::fromHandle('standard'))->toBe(Edition::Standard)
            ->and(Edition::fromHandle('enterprise'))->toBe(Edition::Standard)
            ->and(Edition::fromHandle(null))->toBe(Edition::Standard);
    });

    it('exposes an upgrade message', function () {
        expect(Edition::proUpgradeMessage())->toContain('Pro edition');
    });
});
