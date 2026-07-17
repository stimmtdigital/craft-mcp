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

it('defaults paginationLimit to the SDK default of 50', function () {
    expect((new Settings())->paginationLimit)->toBe(50);
});

it('rejects a paginationLimit below 1', function () {
    $settings = new Settings();
    $settings->paginationLimit = 0;
    $settings->validate();

    expect($settings->hasErrors('paginationLimit'))->toBeTrue();
});
