<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tests/Fixtures/CraftStub.php';
require_once dirname(__DIR__, 3) . '/tests/Fixtures/CustomFieldBehaviorStub.php';

use craft\behaviors\CustomFieldBehavior;
use Mcp\Capability\Attribute\McpResource;
use stimmt\craft\Mcp\resources\ConfigResources;
use stimmt\craft\Mcp\support\ConfigFreshness;

describe('ConfigFreshness', function () {
    it('flags stale only when both versions are known and differ', function () {
        expect(ConfigFreshness::isStale('abc', 'def'))->toBeTrue()
            ->and(ConfigFreshness::isStale('abc', 'abc'))->toBeFalse()
            ->and(ConfigFreshness::isStale(null, 'abc'))->toBeFalse()
            ->and(ConfigFreshness::isStale('abc', null))->toBeFalse();
    });

    it('is a no-op without a full craft app', function () {
        $original = Craft::$app;
        Craft::$app = null;

        ConfigFreshness::ensure();

        Craft::$app = new class () {
        };
        ConfigFreshness::ensure();

        Craft::$app = $original;
        expect(true)->toBeTrue();
    });

    it('accepts an optional RequestContext without changing the no-op behaviour', function () {
        $original = Craft::$app;
        Craft::$app = null;

        ConfigFreshness::ensure(null);

        Craft::$app = $original;
        expect(true)->toBeTrue();
    });

    it('lists exactly the craft:// URIs ConfigResources declares, so a refresh notifies every config resource it could have changed', function () {
        $declared = [];
        foreach ((new ReflectionClass(ConfigResources::class))->getMethods() as $method) {
            foreach ($method->getAttributes(McpResource::class) as $attribute) {
                $declared[] = $attribute->newInstance()->uri;
            }
        }

        $tracked = (new ReflectionClassConstant(ConfigFreshness::class, 'CONFIG_RESOURCE_URIS'))->getValue();

        sort($declared);
        sort($tracked);

        expect($tracked)->toBe($declared);
    });
});

it('patches new handles into the loaded CustomFieldBehavior class', function () {
    $original = CustomFieldBehavior::$fieldHandles;

    try {
        CustomFieldBehavior::$fieldHandles = ['existing' => true];
        ConfigFreshness::patchHandles(['freshHandle', 'existing']);

        expect(CustomFieldBehavior::$fieldHandles)
            ->toHaveKey('freshHandle')
            ->toHaveKey('existing');
    } finally {
        CustomFieldBehavior::$fieldHandles = $original;
    }
});

it('leaves existing handles alone when patching an empty list', function () {
    $original = CustomFieldBehavior::$fieldHandles;

    try {
        CustomFieldBehavior::$fieldHandles = ['existing' => true];
        ConfigFreshness::patchHandles([]);

        expect(CustomFieldBehavior::$fieldHandles)->toBe(['existing' => true]);
    } finally {
        CustomFieldBehavior::$fieldHandles = $original;
    }
});
