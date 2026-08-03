<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';
if (!class_exists('Craft', false)) {
    require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
}

use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;
use stimmt\craft\Mcp\services\McpServerFactory;

it('teaches the tool-selection ladder in the base instructions', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'baseInstructions');
    $instructions = $method->invoke(new McpServerFactory());

    expect($instructions)->toContain('## Choosing Tools')
        ->toContain('count_entries')
        ->toContain('query_graphql')
        ->toContain('get_database_schema')
        ->toContain('information_schema')
        ->toContain('last resort');
});

it('softens the Full scope note to admit only what this install exposes', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'scopeNote');
    $note = $method->invoke(new McpServerFactory(), Scope::Full);

    expect($note)->toContain('every tool the server exposes on this install');
});

it('returns an empty availability note when nothing cited is disabled', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'availabilityNote');

    expect($method->invoke(new McpServerFactory(), []))->toBe('');
});

it('lists exactly the given disabled tool names in the availability note', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'availabilityNote');
    $note = $method->invoke(new McpServerFactory(), ['run_query', 'tinker']);

    expect($note)->toContain('## Availability')
        ->toContain('`run_query`')
        ->toContain('`tinker`')
        ->not->toContain('`get_entry`');
});

it('leaves the install note absent from getInstructions() when additionalInstructions is unset', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'getInstructions');
    $instructions = $method->invoke(new McpServerFactory());

    expect($instructions)->not->toContain('## This Install');
});

it('returns an empty install note for blank additionalInstructions', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'installNote');

    expect($method->invoke(new McpServerFactory(), ''))->toBe('')
        ->and($method->invoke(new McpServerFactory(), '   '))->toBe('');
});

it('renders non-empty additionalInstructions under a This Install heading', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'installNote');
    $note = $method->invoke(new McpServerFactory(), 'Read the house style guide before writing content.');

    expect($note)->toContain('## This Install')
        ->toContain('Read the house style guide before writing content.');
});

it('appends the install note absolutely last, after the availability note', function () {
    $settingsProperty = new ReflectionProperty(Mcp::class, 'loadedSettings');
    $original = $settingsProperty->getValue();

    try {
        $settings = new Settings();
        $settings->disabledTools = ['run_query'];
        $settings->additionalInstructions = 'Read the house style guide before writing content.';
        $settingsProperty->setValue(null, $settings);

        $method = new ReflectionMethod(McpServerFactory::class, 'getInstructions');
        $instructions = $method->invoke(new McpServerFactory());

        expect($instructions)->toContain('## Availability')
            ->toContain('## This Install')
            ->toContain('Read the house style guide before writing content.')
            ->and(strpos($instructions, '## Availability'))->toBeLessThan(strpos($instructions, '## This Install'));
    } finally {
        $settingsProperty->setValue(null, $original);
    }
});
