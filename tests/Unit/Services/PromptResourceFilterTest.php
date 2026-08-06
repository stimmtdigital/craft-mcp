<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use Mcp\Capability\Registry;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition as SdkResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Psr\Log\NullLogger;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;
use stimmt\craft\Mcp\services\McpServerFactory;
use stimmt\craft\Mcp\support\EventDispatcher;

/**
 * Mirrors ToolFilterDenyByDefaultTest for prompts and resources.
 * disabledPrompts/disabledResources were documented (docs/configuration.md,
 * docs/prompts.md, docs/resources.md) but never enforced: Mcp::isPromptEnabled()
 * and Mcp::isResourceEnabled() had no callers, and nothing ever unregistered a
 * disabled or discovery-only prompt/resource from the live SDK registry.
 */
it('unregisters a discovery-registered prompt with no definition, keeping a real one', function () {
    $prompt = static fn (string $name): Prompt => new Prompt(name: $name);

    $registry = new Registry(new EventDispatcher(), new NullLogger());
    $registry->registerPrompt($prompt('create_entry_guide'), static fn (): string => 'ok');
    $registry->registerPrompt($prompt('stray_discovery_prompt'), static fn (): string => 'ok');

    $method = new ReflectionMethod(McpServerFactory::class, 'filterPrompts');
    $method->invoke(new McpServerFactory(), $registry);

    $names = array_keys($registry->getPrompts()->references);

    expect($names)->toContain('create_entry_guide')
        ->and($names)->not->toContain('stray_discovery_prompt');
});

it('unregisters a discovery-registered resource and template with no definition, keeping real ones', function () {
    $registry = new Registry(new EventDispatcher(), new NullLogger());
    $registry->registerResource(
        new SdkResourceDefinition(uri: 'craft://guides/content-writing', name: 'content-writing-guide'),
        static fn (): string => 'ok',
    );
    $registry->registerResource(
        new SdkResourceDefinition(uri: 'craft://stray/resource', name: 'stray-resource'),
        static fn (): string => 'ok',
    );
    $registry->registerResourceTemplate(
        new ResourceTemplate(uriTemplate: 'craft://entries/{section}', name: 'section-entries'),
        static fn (): string => 'ok',
    );
    $registry->registerResourceTemplate(
        new ResourceTemplate(uriTemplate: 'craft://stray/{thing}', name: 'stray-template'),
        static fn (): string => 'ok',
    );

    $method = new ReflectionMethod(McpServerFactory::class, 'filterResources');
    $method->invoke(new McpServerFactory(), $registry);

    $uris = array_keys($registry->getResources()->references);
    $templateUris = array_keys($registry->getResourceTemplates()->references);

    expect($uris)->toContain('craft://guides/content-writing')
        ->and($uris)->not->toContain('craft://stray/resource')
        ->and($templateUris)->toContain('craft://entries/{section}')
        ->and($templateUris)->not->toContain('craft://stray/{thing}');
});

it('unregisters a prompt disabled via disabledPrompts even though it has a real definition', function () {
    $settingsProperty = new ReflectionProperty(Mcp::class, 'loadedSettings');
    $original = $settingsProperty->getValue();

    try {
        $settings = new Settings();
        $settings->disabledPrompts = ['create_entry_guide'];
        $settingsProperty->setValue(null, $settings);

        $registry = new Registry(new EventDispatcher(), new NullLogger());
        $registry->registerPrompt(new Prompt(name: 'create_entry_guide'), static fn (): string => 'ok');

        $method = new ReflectionMethod(McpServerFactory::class, 'filterPrompts');
        $method->invoke(new McpServerFactory(), $registry);

        expect(array_keys($registry->getPrompts()->references))->not->toContain('create_entry_guide');
    } finally {
        $settingsProperty->setValue(null, $original);
    }
});

it('unregisters a resource disabled via disabledResources even though it has a real definition', function () {
    $settingsProperty = new ReflectionProperty(Mcp::class, 'loadedSettings');
    $original = $settingsProperty->getValue();

    try {
        $settings = new Settings();
        $settings->disabledResources = ['craft://guides/content-writing'];
        $settingsProperty->setValue(null, $settings);

        $registry = new Registry(new EventDispatcher(), new NullLogger());
        $registry->registerResource(
            new SdkResourceDefinition(uri: 'craft://guides/content-writing', name: 'content-writing-guide'),
            static fn (): string => 'ok',
        );

        $method = new ReflectionMethod(McpServerFactory::class, 'filterResources');
        $method->invoke(new McpServerFactory(), $registry);

        expect(array_keys($registry->getResources()->references))->not->toContain('craft://guides/content-writing');
    } finally {
        $settingsProperty->setValue(null, $original);
    }
});
