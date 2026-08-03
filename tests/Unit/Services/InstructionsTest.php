<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\Scope;
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

    expect($method->invoke(null, []))->toBe('');
});

it('lists exactly the given disabled tool names in the availability note', function () {
    $method = new ReflectionMethod(McpServerFactory::class, 'availabilityNote');
    $note = $method->invoke(null, ['run_query', 'tinker']);

    expect($note)->toContain('## Availability')
        ->toContain('`run_query`')
        ->toContain('`tinker`')
        ->not->toContain('`get_entry`');
});
