<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;
use stimmt\craft\Mcp\services\ServerFactory;

it('teaches the tool-selection ladder in the base instructions', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'baseInstructions');
    $instructions = $method->invoke(new ServerFactory());

    expect($instructions)->toContain('## Choosing Tools')
        ->toContain('count_entries')
        ->toContain('query_graphql')
        ->toContain('get_database_schema')
        ->toContain('information_schema')
        ->toContain('last resort');
});

it('appends a Lite edition note that retracts the write promises', function () {
    $note = (new ReflectionMethod(ServerFactory::class, 'editionNoteFor'))
        ->invoke(new ServerFactory(), Edition::Lite);

    expect($note)->toContain('Lite edition')
        ->toContain('create_entry')
        ->toContain('not available');
});

it('adds no edition note on Pro', function () {
    $note = (new ReflectionMethod(ServerFactory::class, 'editionNoteFor'))
        ->invoke(new ServerFactory(), Edition::Pro);

    expect($note)->toBe('');
});

it('softens the Full scope note to admit only what this install exposes', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'scopeNote');
    $note = $method->invoke(new ServerFactory(), Scope::Full);

    expect($note)->toContain('every tool the server exposes on this install');
});

it('returns an empty availability note when nothing cited is disabled', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'availabilityNote');

    expect($method->invoke(new ServerFactory(), []))->toBe('');
});

it('lists exactly the given disabled tool names in the availability note', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'availabilityNote');
    $note = $method->invoke(new ServerFactory(), ['run_query', 'tinker']);

    expect($note)->toContain('## Availability')
        ->toContain('`run_query`')
        ->toContain('`tinker`')
        ->not->toContain('`get_entry`');
});

it('leaves the install note absent from getInstructions() when additionalInstructions is unset', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'getInstructions');
    $instructions = $method->invoke(new ServerFactory());

    expect($instructions)->not->toContain('## This Install');
});

it('returns an empty install note for blank additionalInstructions', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'installNote');

    expect($method->invoke(new ServerFactory(), ''))->toBe('')
        ->and($method->invoke(new ServerFactory(), '   '))->toBe('');
});

it('renders non-empty additionalInstructions under a This Install heading', function () {
    $method = new ReflectionMethod(ServerFactory::class, 'installNote');
    $note = $method->invoke(new ServerFactory(), 'Read the house style guide before writing content.');

    expect($note)->toContain('## This Install')
        ->toContain('Read the house style guide before writing content.');
});

// Drift guard for #50: CITED_TOOLS must stay a bijection with the tool names
// the base instructions actually cite, or the availability note silently
// stops covering a newly cited tool and the instructions lie again.
it('keeps CITED_TOOLS in sync with the tool names cited in the base instructions', function () {
    $base = (new ReflectionMethod(ServerFactory::class, 'baseInstructions'))->invoke(new ServerFactory());
    preg_match_all('/`([a-z0-9_]+)`/', $base, $matches);

    $registryNames = array_keys(Mcp::getToolRegistry()->getDefinitions());
    $cited = array_values(array_unique(array_intersect($matches[1], $registryNames)));
    $declared = (new ReflectionClassConstant(ServerFactory::class, 'CITED_TOOLS'))->getValue();

    sort($cited);
    sort($declared);

    expect($cited)->toBe($declared);
});

it('appends the install note absolutely last, after the availability note', function () {
    $settingsProperty = new ReflectionProperty(Mcp::class, 'loadedSettings');
    $original = $settingsProperty->getValue();

    try {
        $settings = new Settings();
        $settings->disabledTools = ['run_query'];
        $settings->additionalInstructions = 'Read the house style guide before writing content.';
        $settingsProperty->setValue(null, $settings);

        $method = new ReflectionMethod(ServerFactory::class, 'getInstructions');
        $instructions = $method->invoke(new ServerFactory());

        expect($instructions)->toContain('## Availability')
            ->toContain('## This Install')
            ->toContain('Read the house style guide before writing content.')
            ->and(strpos($instructions, '## Availability'))->toBeLessThan(strpos($instructions, '## This Install'));
    } finally {
        $settingsProperty->setValue(null, $original);
    }
});
