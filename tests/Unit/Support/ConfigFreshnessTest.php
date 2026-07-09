<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tests/Fixtures/CraftStub.php';

use stimmt\craft\Mcp\support\ConfigFreshness;

describe('ConfigFreshness', function () {
    it('flags stale only when both versions are known and differ', function () {
        expect(ConfigFreshness::isStale('abc', 'def'))->toBeTrue()
            ->and(ConfigFreshness::isStale('abc', 'abc'))->toBeFalse()
            ->and(ConfigFreshness::isStale(null, 'abc'))->toBeFalse()
            ->and(ConfigFreshness::isStale('abc', null))->toBeFalse();
    });

    it('is a no-op without a full craft app', function () {
        $original = Craft::$app;
        Craft::$app = null;

        ConfigFreshness::ensure();

        Craft::$app = new class () {
        };
        ConfigFreshness::ensure();

        Craft::$app = $original;
        expect(true)->toBeTrue();
    });
});
