<?php

declare(strict_types=1);

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use stimmt\craft\Mcp\elements\refs\AssetKey;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Resolution;

describe('Keys', function () {
    it('routes asset keys through AssetKey', function () {
        $keys = new Keys(assets: new AssetKey(
            lookupId: fn (): ?int => 42,
            lookupKey: fn (): ?array => ['volume' => 'images', 'filename' => 'hero.jpg'],
        ));

        expect($keys->resolve(Asset::class, ['volume' => 'images', 'filename' => 'hero.jpg'], null)->id)->toBe(42)
            ->and($keys->keyFor(Asset::class, 42, null))->toBe(['volume' => 'images', 'filename' => 'hero.jpg']);
    });

    it('routes other targets through the injected lookups with the element type', function () {
        $keys = new Keys(
            lookupId: fn (string $type, array $key, ?string $site): Resolution => match ([$type, $key, $site]) {
                [Entry::class, ['section' => 'pages', 'slug' => 'about'], 'en'] => Resolution::one(7),
                [Category::class, ['group' => 'topics', 'slug' => 'news'], 'en'] => Resolution::one(8),
                [Tag::class, ['group' => 'labels', 'slug' => 'hot'], 'en'] => Resolution::one(9),
                [User::class, ['username' => 'max'], 'en'] => Resolution::one(10),
                default => Resolution::none(),
            },
            lookupKey: fn (string $type, int $id, ?string $site): ?array => $id === 7
                ? ['section' => 'pages', 'slug' => 'about']
                : null,
        );

        expect($keys->resolve(Entry::class, ['section' => 'pages', 'slug' => 'about'], 'en')->id)->toBe(7)
            ->and($keys->resolve(Category::class, ['group' => 'topics', 'slug' => 'news'], 'en')->id)->toBe(8)
            ->and($keys->resolve(Tag::class, ['group' => 'labels', 'slug' => 'hot'], 'en')->id)->toBe(9)
            ->and($keys->resolve(User::class, ['username' => 'max'], 'en')->id)->toBe(10)
            ->and($keys->keyFor(Entry::class, 7, 'en'))->toBe(['section' => 'pages', 'slug' => 'about']);
    });

    it('declares support only for the five core targets plus global sets', function () {
        expect($keys = new Keys())->toBeInstanceOf(Keys::class)
            ->and($keys->supports(Entry::class))->toBeTrue()
            ->and($keys->supports(Asset::class))->toBeTrue()
            ->and($keys->supports('some\\plugin\\elements\\Product'))->toBeFalse();
    });

    it('returns null for malformed keys without calling lookups', function () {
        $keys = new Keys(lookupId: function (): Resolution {
            throw new RuntimeException('must not be called');
        });

        expect($keys->resolve(Entry::class, ['slug' => 'about'], null)->id)->toBeNull()
            ->and($keys->resolve(User::class, [], null)->id)->toBeNull();
    });

    it('exposes the natural-key shape per target type', function () {
        $keys = new Keys();

        expect($keys->keyShape(Entry::class))->toBe(['section', 'slug'])
            ->and($keys->keyShape(Category::class))->toBe(['group', 'slug'])
            ->and($keys->keyShape(Tag::class))->toBe(['group', 'slug'])
            ->and($keys->keyShape(User::class))->toBe(['username'])
            ->and($keys->keyShape(GlobalSet::class))->toBe(['handle'])
            ->and($keys->keyShape(Asset::class))->toBe(['volume', 'path?', 'filename'])
            ->and($keys->keyShape('some\\plugin\\Product'))->toBeNull();
    });

    it('separates a key that matches nothing from one that matches too much', function () {
        expect(Resolution::none()->id)->toBeNull()
            ->and(Resolution::none()->ambiguous)->toBeFalse()
            ->and(Resolution::ambiguous()->id)->toBeNull()
            ->and(Resolution::ambiguous()->ambiguous)->toBeTrue()
            ->and(Resolution::one(7)->id)->toBe(7)
            ->and(Resolution::one(7)->ambiguous)->toBeFalse();
    });

    it('never offers an id for an ambiguous key, so no caller can relate to a guess', function () {
        $keys = new Keys(lookupId: fn (): Resolution => Resolution::ambiguous());

        $resolution = $keys->resolve(Entry::class, ['section' => 'pages', 'slug' => 'child'], null);

        expect($resolution->id)->toBeNull()
            ->and($resolution->ambiguous)->toBeTrue();
    });

    // An asset is addressed by volume plus path plus filename, which the
    // filesystem already keeps unique, so the ambiguous case cannot arise and
    // must not be invented for it.
    it('treats an asset key as unambiguous', function () {
        $keys = new Keys(assets: new AssetKey(lookupId: fn (): ?int => 42));

        expect($keys->resolve(Asset::class, ['volume' => 'images', 'filename' => 'hero.jpg'], null)->ambiguous)->toBeFalse();
    });
});
