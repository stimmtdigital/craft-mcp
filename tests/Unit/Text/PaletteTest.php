<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\text\Ansi;
use stimmt\craft\Mcp\text\Palette;

describe('Palette when colour is off', function () {
    it('returns every role unchanged', function (string $role) {
        $palette = new Palette(false);

        expect($palette->{$role}('text'))->toBe('text');
    })->with(['heading', 'key', 'muted', 'subtle', 'error', 'warning']);

    it('emits no escape sequence at all', function () {
        $palette = new Palette(false);

        $painted = $palette->heading('a') . $palette->key('b') . $palette->muted('c')
            . $palette->subtle('d') . $palette->error('e') . $palette->warning('f');

        expect($painted)->toBe('abcdef')
            ->and($painted)->not->toContain("\033");
    });

    it('reports itself as disabled', function () {
        expect((new Palette(false))->enabled())->toBeFalse();
    });
});

describe('Palette when colour is on', function () {
    it('wraps each role in its own escape sequence', function (string $role, string $style) {
        $palette = new Palette(true);

        expect($palette->{$role}('text'))->toBe($style . 'text' . Ansi::RESET);
    })->with([
        ['heading', Ansi::BOLD],
        ['key', Ansi::CYAN],
        ['muted', Ansi::DIM],
        ['subtle', Ansi::GRAY],
        ['error', Ansi::RED],
        ['warning', Ansi::YELLOW],
    ]);

    it('reports itself as enabled', function () {
        expect((new Palette(true))->enabled())->toBeTrue();
    });
});

describe('Palette::fromSettings()', function () {
    it('is off when the install has not opted in', function () {
        expect(Palette::fromSettings()->enabled())->toBeFalse();
    });
});
