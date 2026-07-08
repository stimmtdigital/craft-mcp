<?php

declare(strict_types=1);

use stimmt\craft\Mcp\elements\refs\AssetKey;

describe('AssetKey', function () {
    it('resolves a key to an id through the injected lookup', function () {
        $assetKey = new AssetKey(
            lookupId: fn (string $volume, string $path, string $filename): ?int => ($volume === 'images' && $path === 'heroes/' && $filename === 'hero.jpg') ? 42 : null,
        );

        expect($assetKey->idFor(['volume' => 'images', 'path' => 'heroes/', 'filename' => 'hero.jpg']))->toBe(42)
            ->and($assetKey->idFor(['volume' => 'images', 'filename' => 'nope.jpg']))->toBeNull();
    });

    it('defaults path to empty when omitted', function () {
        $seen = [];
        $assetKey = new AssetKey(lookupId: function (string $volume, string $path, string $filename) use (&$seen): ?int {
            $seen = [$volume, $path, $filename];

            return 1;
        });

        $assetKey->idFor(['volume' => 'images', 'filename' => 'hero.jpg']);
        expect($seen)->toBe(['images', '', 'hero.jpg']);
    });

    it('builds a key from an id through the injected lookup', function () {
        $assetKey = new AssetKey(lookupKey: fn (int $id): ?array => $id === 42
            ? ['volume' => 'images', 'path' => 'heroes/', 'filename' => 'hero.jpg']
            : null);

        expect($assetKey->keyFor(42))->toBe(['volume' => 'images', 'path' => 'heroes/', 'filename' => 'hero.jpg'])
            ->and($assetKey->keyFor(1))->toBeNull();
    });

    it('rejects malformed keys', function () {
        $assetKey = new AssetKey(lookupId: fn (): ?int => 1);

        expect($assetKey->idFor(['filename' => 'x.jpg']))->toBeNull()
            ->and($assetKey->idFor(['volume' => 'images']))->toBeNull();
    });
});
