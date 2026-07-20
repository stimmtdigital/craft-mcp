<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\Mcp;

describe('Mcp editions', function () {
    it('declares standard and pro in order', function () {
        expect(Mcp::editions())->toBe(['standard', 'pro']);
    });

    it('resolves the current edition, defaulting to Standard', function () {
        expect(Mcp::currentEdition())->toBeInstanceOf(Edition::class);
    });
});
