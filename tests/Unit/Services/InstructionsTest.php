<?php

declare(strict_types=1);

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

it('appends a Standard edition note that retracts the write promises', function () {
    $note = (new ReflectionMethod(McpServerFactory::class, 'editionNoteFor'))
        ->invoke(new McpServerFactory(), \stimmt\craft\Mcp\enums\Edition::Standard);

    expect($note)->toContain('Standard edition')
        ->toContain('create_entry')
        ->toContain('not available');
});

it('adds no edition note on Pro', function () {
    $note = (new ReflectionMethod(McpServerFactory::class, 'editionNoteFor'))
        ->invoke(new McpServerFactory(), \stimmt\craft\Mcp\enums\Edition::Pro);

    expect($note)->toBe('');
});
