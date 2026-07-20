<?php

declare(strict_types=1);

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
