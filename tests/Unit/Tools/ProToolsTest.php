<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\capabilities\EntryTools;
use stimmt\craft\Mcp\capabilities\EntryWorkflowTools;
use stimmt\craft\Mcp\capabilities\NestedEntryTools;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\integration\Gate;

it('marks every content-writing tool as requiring Pro', function (string $class, string $method) {
    expect(Gate::requiredEdition(new ReflectionMethod($class, $method)))->toBe(Edition::Pro);
})->with([
    [EntryWorkflowTools::class, 'publishEntry'],
    [EntryWorkflowTools::class, 'copyEntryToSite'],
    [NestedEntryTools::class, 'createNestedEntry'],
    [NestedEntryTools::class, 'moveNestedEntry'],
]);

it('leaves the entry reads on Lite', function (string $method) {
    expect(Gate::requiredEdition(new ReflectionMethod(EntryTools::class, $method)))->toBe(Edition::Lite);
})->with([['countEntries'], ['resolveRef'], ['previewUrl']]);

// Closed-set guard: a stray #[RequiresEdition(Edition::Pro)] on any other tool
// (say stimmt_logs_read) would silently paywall it. Every capability class is
// scanned through the Gate's own precedence rule (method over class), so the
// set asserted is the set the Gate enforces.
it('marks exactly the documented content-writing tools as Pro across every capability class', function () {
    $proMethods = [];

    foreach ((array) glob(dirname(__DIR__, 3) . '/src/capabilities/*.php') as $file) {
        $class = 'stimmt\\craft\\Mcp\\capabilities\\' . basename((string) $file, '.php');

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(McpTool::class) !== [] && Gate::requiredEdition($method) === Edition::Pro) {
                $proMethods[] = $method->getName();
            }
        }
    }

    sort($proMethods);

    // Every tool that writes content, and nothing else. The nested-block pair
    // is here because creating and reordering blocks is writing content: a
    // gate that let those through would sell the tier and then leak it.
    expect($proMethods)->toBe([
        'copyEntryToSite',
        'createNestedEntry',
        'moveNestedEntry',
        'publishEntry',
    ]);
});
