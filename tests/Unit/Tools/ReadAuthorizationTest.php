<?php

declare(strict_types=1);

use stimmt\craft\Mcp\tools\AssetTools;
use stimmt\craft\Mcp\tools\CategoryTools;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;
use stimmt\craft\Mcp\tools\UserTools;

// Every element-backed read tool must pass through an Authorization seam, so
// permission-scoped tokens never read past the user's view permissions. New
// read tools inherit this guarantee or turn this test red.
it('routes every element read through an Authorization seam', function (string $class, string $method, string $seam) {
    $reflection = new ReflectionMethod($class, $method);
    $src = implode("\n", array_slice(
        explode("\n", (string) file_get_contents($reflection->getFileName())),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
    expect($src)->toContain("Authorization::{$seam}(");
})->with([
    [EntryTools::class, 'listEntries', 'scopeQuery'],
    [EntryTools::class, 'countEntries', 'scopeQuery'],
    [EntryTools::class, 'getEntry', 'assertCanView'],
    [EntryTools::class, 'describeEntrySchema', 'assertCanView'],
    [EntryWorkflowTools::class, 'listDrafts', 'scopeQuery'],
    [EntryWorkflowTools::class, 'listRevisions', 'scopeQuery'],
    [AssetTools::class, 'listAssets', 'scopeQuery'],
    [AssetTools::class, 'getAsset', 'assertCanView'],
    [CategoryTools::class, 'listCategories', 'scopeQuery'],
    [UserTools::class, 'listUsers', 'scopeQuery'],
]);
