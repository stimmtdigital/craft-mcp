<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\models\Settings;

describe('Settings http transport', function () {
    it('defaults to a disabled transport with safe values', function () {
        $settings = new Settings();

        expect($settings->httpTransport)->toBeFalse()
            ->and($settings->httpPath)->toBe('mcp')
            ->and($settings->httpSessionTtl)->toBe(3600);
    });

    it('validates the new settings', function () {
        $settings = new Settings();
        $settings->httpPath = '';
        $settings->httpSessionTtl = 0;

        expect($settings->validate())->toBeFalse()
            ->and(array_keys($settings->getErrors()))->toContain('httpPath', 'httpSessionTtl');
    });
});
