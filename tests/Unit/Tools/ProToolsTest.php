<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;
use stimmt\craft\Mcp\tools\NestedEntryTools;

it('marks every content-writing tool as requiring Pro', function (string $class, string $method) {
    $attrs = (new ReflectionMethod($class, $method))->getAttributes(RequiresEdition::class);
    expect($attrs)->not->toBeEmpty()
        ->and($attrs[0]->newInstance()->edition)->toBe(Edition::Pro);
})->with([
    [EntryTools::class, 'createEntry'],
    [EntryTools::class, 'updateEntry'],
    [EntryWorkflowTools::class, 'publishEntry'],
    [EntryWorkflowTools::class, 'deleteEntry'],
    [EntryWorkflowTools::class, 'duplicateEntry'],
    [EntryWorkflowTools::class, 'copyEntryToSite'],
    [NestedEntryTools::class, 'createNestedEntry'],
    [NestedEntryTools::class, 'moveNestedEntry'],
]);

it('leaves content reads and schema on Lite', function (string $class, string $method) {
    expect((new ReflectionMethod($class, $method))->getAttributes(RequiresEdition::class))->toBeEmpty();
})->with([
    [EntryTools::class, 'listEntries'],
    [EntryTools::class, 'getEntry'],
    [EntryTools::class, 'countEntries'],
    [EntryTools::class, 'describeEntrySchema'],
    [EntryWorkflowTools::class, 'listDrafts'],
    [EntryWorkflowTools::class, 'listRevisions'],
]);

// Closed-set guard: a stray #[RequiresEdition(Edition::Pro)] on any other tool
// (say clear_caches) would silently paywall it. Scan every tool class and assert
// the Pro set is exactly the documented content-writing tools. Mirrors the
// class-then-method precedence the extractor uses.
it('marks exactly the six documented tools as Pro across every tool class', function () {
    $proMethods = [];

    foreach (glob(dirname(__DIR__, 3) . '/src/tools/*.php') as $file) {
        $class = 'stimmt\\craft\\Mcp\\tools\\' . basename($file, '.php');
        if (!class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $classAttrs = $reflection->getAttributes(RequiresEdition::class);
        $classEdition = $classAttrs === [] ? null : $classAttrs[0]->newInstance()->edition;

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(McpTool::class) === []) {
                continue;
            }

            $methodAttrs = $method->getAttributes(RequiresEdition::class);
            $edition = $methodAttrs === []
                ? ($classEdition ?? Edition::Lite)
                : $methodAttrs[0]->newInstance()->edition;

            if ($edition === Edition::Pro) {
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
        'createEntry',
        'createNestedEntry',
        'deleteEntry',
        'duplicateEntry',
        'moveNestedEntry',
        'publishEntry',
        'updateEntry',
    ]);
});
