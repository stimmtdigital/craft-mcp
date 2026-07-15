<?php

declare(strict_types=1);

use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;

// Every entry-write tool must consult the acting-user guard before writing,
// so content-scope HTTP tokens inherit the linked user's real CP permissions
// (issue #34). Method => the assertion its body must contain.
it('guards every entry-write tool with the acting-user authorization', function (string $class, string $method, string $assertion) {
    $reflection = new ReflectionMethod($class, $method);
    $file = (string) file_get_contents($reflection->getFileName());
    $lines = array_slice(
        explode("\n", $file),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    );

    expect(implode("\n", $lines))->toContain("Authorization::{$assertion}(");
})->with([
    [EntryTools::class, 'createEntry', 'assertCanSave'],
    [EntryTools::class, 'updateEntry', 'assertCanSave'],
    [EntryWorkflowTools::class, 'publishEntry', 'assertCanPublish'],
    [EntryWorkflowTools::class, 'deleteEntry', 'assertCanDelete'],
    [EntryWorkflowTools::class, 'duplicateEntry', 'assertCanDuplicate'],
    [EntryWorkflowTools::class, 'copyEntryToSite', 'assertCanSave'],
    // The canonical-id publish path applies drafts[0]; the guard must check
    // that draft object itself (peer-draft permissions), not only the canonical.
    [EntryWorkflowTools::class, 'publishCanonical', 'assertCanPublish'],
]);
