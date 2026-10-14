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

it('defaults paginationLimit to 100 so one page covers all registered tools', function () {
    expect((new Settings())->paginationLimit)->toBe(100);
});

it('rejects a paginationLimit below 1', function () {
    $settings = new Settings();
    $settings->paginationLimit = 0;
    $settings->validate();

    expect($settings->hasErrors('paginationLimit'))->toBeTrue();
});

it('defaults httpSessionStore to null (built-in DB store)', function () {
    expect((new Settings())->httpSessionStore)->toBeNull();
});

it('defaults showLockedProTools to false and validates it as boolean', function () {
    $settings = new Settings();
    expect($settings->showLockedProTools)->toBeFalse();

    $settings->showLockedProTools = true;
    expect($settings->validate())->toBeTrue();
});

it('defaults additionalInstructions to an empty string and validates as a string', function () {
    $settings = new Settings();

    expect($settings->additionalInstructions)->toBe('');

    $settings->additionalInstructions = 'Read the house style guide before writing content.';
    expect($settings->validate(['additionalInstructions']))->toBeTrue();
});

describe('Settings disabledScopes', function () {
    it('defaults to empty', function () {
        expect((new Settings())->disabledScopes)->toBe([]);
    });

    it('validates entries against real Scope values', function () {
        $settings = new Settings();
        $settings->disabledScopes = ['full'];

        expect($settings->validate(['disabledScopes']))->toBeTrue();
    });

    it('rejects an entry that is not a Scope value', function () {
        $settings = new Settings();
        $settings->disabledScopes = ['nonexistent'];

        expect($settings->validate(['disabledScopes']))->toBeFalse();
    });

    it('is case-sensitive: an uppercase scope name is rejected', function () {
        $settings = new Settings();
        $settings->disabledScopes = ['Full'];

        expect($settings->validate(['disabledScopes']))->toBeFalse();
    });
});

describe('Settings showClientConfigSnippet', function () {
    it('defaults to true', function () {
        expect((new Settings())->showClientConfigSnippet)->toBeTrue();
    });

    it('validates as boolean', function () {
        $settings = new Settings();
        $settings->showClientConfigSnippet = false;

        expect($settings->validate(['showClientConfigSnippet']))->toBeTrue();
    });
});

describe('Settings colorOutput', function () {
    it('defaults to false so a model client never pays for escape sequences', function () {
        expect((new Settings())->colorOutput)->toBeFalse();
    });

    it('validates as boolean', function () {
        $settings = new Settings();
        $settings->colorOutput = true;

        expect($settings->validate(['colorOutput']))->toBeTrue();
    });
});

describe('Settings preview links', function () {
    it('hands out preview links by default, at Craft\'s own token duration', function () {
        $settings = new Settings();

        expect($settings->previewUrls)->toBeTrue()
            ->and($settings->previewTokenDuration)->toBeNull();
    });

    it('refuses a preview token that would expire within a minute', function () {
        $settings = new Settings();
        $settings->previewTokenDuration = 30;

        expect($settings->validate(['previewTokenDuration']))->toBeFalse();

        $settings->previewTokenDuration = 3600;
        expect($settings->validate(['previewTokenDuration']))->toBeTrue();
    });
});

describe('Settings OAuth connector', function () {
    it('is off by default, with the Claude callbacks allowed once it is on', function () {
        $settings = new Settings();

        expect($settings->oauth)->toBeFalse()
            ->and($settings->oauthRedirectUris)->toContain('https://claude.ai/api/mcp/auth_callback', 'http://localhost/callback')
            ->and($settings->validate())->toBeTrue();
    });

    it('refuses a redirect allowlist entry that is not an http(s) URL', function () {
        $settings = new Settings();
        $settings->oauthRedirectUris = ['javascript:alert(1)'];

        expect($settings->validate(['oauthRedirectUris']))->toBeFalse();
    });
});
