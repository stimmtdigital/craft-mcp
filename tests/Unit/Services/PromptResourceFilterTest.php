<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\PromptDefinition;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\models\Settings;
use stimmt\craft\Mcp\policy\Gate;

/**
 * The disabledPrompts and disabledResources settings, asked of the rule rather
 * than of whichever class currently applies it. These were reflection tests
 * against private methods on the server factory; the rule moved to the Gate
 * without changing, and a test that breaks on a move it should not notice is
 * pinning the wrong thing.
 */
function withSettings(callable $configure, callable $assert): void {
    $property = new ReflectionProperty(Mcp::class, 'loadedSettings');
    $original = $property->getValue();

    try {
        $settings = new Settings();
        $configure($settings);
        $property->setValue(null, $settings);
        $assert();
    } finally {
        $property->setValue(null, $original);
    }
}

function promptDefinition(string $name): PromptDefinition {
    return new PromptDefinition(
        name: $name,
        description: 'fixture',
        class: 'Fixture',
        method: 'run',
        source: 'core',
        category: 'content',
    );
}

function resourceDefinition(string $uri): ResourceDefinition {
    return new ResourceDefinition(
        uri: $uri,
        name: 'fixture',
        description: 'fixture',
        class: 'Fixture',
        method: 'run',
        source: 'core',
        category: 'content',
    );
}

it('refuses a prompt named in disabledPrompts', function () {
    withSettings(
        static function (Settings $settings): void {
            $settings->disabledPrompts = ['create_entry_guide'];
        },
        static function (): void {
            $gate = new Gate();

            expect($gate->admitsPrompt(promptDefinition('create_entry_guide'))->allowed)->toBeFalse()
                ->and($gate->admitsPrompt(promptDefinition('other_guide'))->allowed)->toBeTrue();
        },
    );
});

it('refuses a resource named in disabledResources', function () {
    withSettings(
        static function (Settings $settings): void {
            $settings->disabledResources = ['craft://guides/content-writing'];
        },
        static function (): void {
            $gate = new Gate();

            expect($gate->admitsResource(resourceDefinition('craft://guides/content-writing'))->allowed)->toBeFalse()
                ->and($gate->admitsResource(resourceDefinition('craft://schema/sections'))->allowed)->toBeTrue();
        },
    );
});

it('says why it refused, so a missing element is explainable', function () {
    withSettings(
        static function (Settings $settings): void {
            $settings->disabledPrompts = ['create_entry_guide'];
        },
        static function (): void {
            expect((new Gate())->admitsPrompt(promptDefinition('create_entry_guide'))->reason)
                ->toContain('disabled by settings');
        },
    );
});
