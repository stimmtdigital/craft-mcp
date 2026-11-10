<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\models\Settings;

/**
 * v2 keeps five settings; everything about transport, tokens, OAuth and
 * output belongs to Craft's own MCP server now. The property list is pinned
 * so a v1 key cannot quietly come back: Mcp::applyConfigOverrides() copies
 * any key of config/craft-mcp.php that is a property, so a stray property
 * would turn a dead config key live again.
 */
it('has exactly the v2 property set', function () {
    expect(array_keys(get_class_vars(Settings::class)))->toEqualCanonicalizing([
        'enabled',
        'disabledTools',
        'enableDangerousTools',
        'maxResponseBytes',
        'previewTokenDuration',
    ]);
});

it('defaults to the tools on, nothing disabled, dangerous tools on and a 1 MB response cap', function () {
    $settings = new Settings();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->disabledTools)->toBe([])
        ->and($settings->enableDangerousTools)->toBeTrue()
        ->and($settings->maxResponseBytes)->toBe(1048576)
        ->and($settings->previewTokenDuration)->toBeNull()
        ->and($settings->validate())->toBeTrue();
});

describe('Settings disabledTools', function () {
    it('accepts a list of tool names', function () {
        $settings = new Settings();
        $settings->disabledTools = ['stimmt_tinker', 'stimmt_db_query'];

        expect($settings->validate(['disabledTools']))->toBeTrue();
    });

    it('refuses an entry that is not a string', function () {
        $settings = new Settings();
        $settings->disabledTools = [['stimmt_tinker']];

        expect($settings->validate(['disabledTools']))->toBeFalse();
    });
});

describe('Settings maxResponseBytes', function () {
    // Below 1 KB even an error message would not fit, so every list tool
    // would refuse every call.
    it('refuses a cap below 1 KB', function () {
        $settings = new Settings();
        $settings->maxResponseBytes = 1023;

        expect($settings->validate(['maxResponseBytes']))->toBeFalse();

        $settings->maxResponseBytes = 1024;
        expect($settings->validate(['maxResponseBytes']))->toBeTrue();
    });
});

describe('Settings preview links', function () {
    it('refuses a preview token that would expire within a minute', function () {
        $settings = new Settings();
        $settings->previewTokenDuration = 30;

        expect($settings->validate(['previewTokenDuration']))->toBeFalse();

        $settings->previewTokenDuration = 3600;
        expect($settings->validate(['previewTokenDuration']))->toBeTrue();
    });

    it('accepts null, which falls back to Craft\'s own token duration', function () {
        $settings = new Settings();
        $settings->previewTokenDuration = null;

        expect($settings->validate(['previewTokenDuration']))->toBeTrue();
    });
});

it('validates the switches as booleans', function () {
    $settings = new Settings();
    $settings->enabled = false;
    $settings->enableDangerousTools = false;

    expect($settings->validate(['enabled', 'enableDangerousTools']))->toBeTrue();
});
