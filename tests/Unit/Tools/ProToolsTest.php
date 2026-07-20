<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;

it('marks the six entry-mutation tools as requiring Pro', function (string $class, string $method) {
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
]);

it('leaves content reads and schema on Standard', function (string $class, string $method) {
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
// the Pro set is exactly the six documented mutation tools. Mirrors the
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
                ? ($classEdition ?? Edition::Standard)
                : $methodAttrs[0]->newInstance()->edition;

            if ($edition === Edition::Pro) {
                $proMethods[] = $method->getName();
            }
        }
    }

    sort($proMethods);

    expect($proMethods)->toBe([
        'copyEntryToSite',
        'createEntry',
        'deleteEntry',
        'duplicateEntry',
        'publishEntry',
        'updateEntry',
    ]);
});
