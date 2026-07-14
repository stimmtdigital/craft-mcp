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
