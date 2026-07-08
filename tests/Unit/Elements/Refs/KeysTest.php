<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use stimmt\craft\Mcp\elements\refs\AssetKey;
use stimmt\craft\Mcp\elements\refs\Keys;

describe('Keys', function () {
    it('routes asset keys through AssetKey', function () {
        $keys = new Keys(assets: new AssetKey(
            lookupId: fn (): ?int => 42,
            lookupKey: fn (): ?array => ['volume' => 'images', 'filename' => 'hero.jpg'],
        ));

        expect($keys->idFor(Asset::class, ['volume' => 'images', 'filename' => 'hero.jpg'], null))->toBe(42)
            ->and($keys->keyFor(Asset::class, 42, null))->toBe(['volume' => 'images', 'filename' => 'hero.jpg']);
    });

    it('routes other targets through the injected lookups with the element type', function () {
        $keys = new Keys(
            lookupId: fn (string $type, array $key, ?string $site): ?int => match ([$type, $key, $site]) {
                [Entry::class, ['section' => 'pages', 'slug' => 'about'], 'en'] => 7,
                [Category::class, ['group' => 'topics', 'slug' => 'news'], 'en'] => 8,
                [Tag::class, ['group' => 'labels', 'slug' => 'hot'], 'en'] => 9,
                [User::class, ['username' => 'max'], 'en'] => 10,
                default => null,
            },
            lookupKey: fn (string $type, int $id, ?string $site): ?array => $id === 7
                ? ['section' => 'pages', 'slug' => 'about']
                : null,
        );

        expect($keys->idFor(Entry::class, ['section' => 'pages', 'slug' => 'about'], 'en'))->toBe(7)
            ->and($keys->idFor(Category::class, ['group' => 'topics', 'slug' => 'news'], 'en'))->toBe(8)
            ->and($keys->idFor(Tag::class, ['group' => 'labels', 'slug' => 'hot'], 'en'))->toBe(9)
            ->and($keys->idFor(User::class, ['username' => 'max'], 'en'))->toBe(10)
            ->and($keys->keyFor(Entry::class, 7, 'en'))->toBe(['section' => 'pages', 'slug' => 'about']);
    });

    it('declares support only for the five core targets plus global sets', function () {
        expect($keys = new Keys())->toBeInstanceOf(Keys::class)
            ->and($keys->supports(Entry::class))->toBeTrue()
            ->and($keys->supports(Asset::class))->toBeTrue()
            ->and($keys->supports('some\\plugin\\elements\\Product'))->toBeFalse();
    });

    it('returns null for malformed keys without calling lookups', function () {
        $keys = new Keys(lookupId: function (): ?int {
            throw new RuntimeException('must not be called');
        });

        expect($keys->idFor(Entry::class, ['slug' => 'about'], null))->toBeNull()
            ->and($keys->idFor(User::class, [], null))->toBeNull();
    });
});
