<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\Mcp;

// #57 sub-bug: by_source used to count tool CLASSES per source while total
// counts tools, so the summary did not sum. Both must count definitions.
it('sums by_source to total in getSummary()', function () {
    $summary = Mcp::getToolRegistry()->getSummary();

    expect(array_sum($summary['by_source']))->toBe($summary['total'])
        ->and(array_sum($summary['by_category']))->toBe($summary['total']);
});

// The registry definitions are what list_mcp_tools serves and what the
// scope/danger filters run on, so the nested tools must surface here as
// dangerous content tools or they silently fall out of both.
it('registers the nested-entry tools as dangerous content tools', function (string $name) {
    $definition = Mcp::getToolRegistry()->getDefinition($name);

    expect($definition)->not->toBeNull()
        ->and($definition->category)->toBe('content')
        ->and($definition->dangerous)->toBeTrue();
})->with([['create_nested_entry'], ['move_nested_entry']]);
