<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\Edition;

describe('Edition', function () {
    it('orders standard below pro', function () {
        expect(Edition::ordered())->toBe(['lite', 'pro']);
    });

    it('compares editions with atLeast', function () {
        expect(Edition::Pro->atLeast(Edition::Lite))->toBeTrue()
            ->and(Edition::Pro->atLeast(Edition::Pro))->toBeTrue()
            ->and(Edition::Lite->atLeast(Edition::Lite))->toBeTrue()
            ->and(Edition::Lite->atLeast(Edition::Pro))->toBeFalse();
    });

    it('resolves stored handles and falls back to Lite', function () {
        expect(Edition::fromHandle('pro'))->toBe(Edition::Pro)
            ->and(Edition::fromHandle('lite'))->toBe(Edition::Lite)
            ->and(Edition::fromHandle('enterprise'))->toBe(Edition::Lite)
            ->and(Edition::fromHandle(null))->toBe(Edition::Lite);
    });

    it('exposes an upgrade message', function () {
        expect(Edition::proUpgradeMessage())->toContain('Pro edition');
    });
});
