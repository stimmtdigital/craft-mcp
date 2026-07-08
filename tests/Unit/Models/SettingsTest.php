<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\models\Settings;

describe('Settings entryWriteMode', function () {
    it('defaults to draft and validates the allowed values', function () {
        $settings = new Settings();

        expect($settings->entryWriteMode)->toBe('draft');

        $settings->entryWriteMode = 'live';
        expect($settings->validate(['entryWriteMode']))->toBeTrue();

        $settings->entryWriteMode = 'yolo';
        expect($settings->validate(['entryWriteMode']))->toBeFalse();
    });
});
