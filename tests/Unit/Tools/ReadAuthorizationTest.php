<?php

declare(strict_types=1);

use stimmt\craft\Mcp\capabilities\CategoryTools;
use stimmt\craft\Mcp\capabilities\EntryTools;

// Every element-backed read tool that is open to non-admins must pass
// through an Authorization seam, so a connection never reads past the acting
// user's view permissions: Craft's mcp:read scope says a client may read, not
// which sections. New read tools inherit this guarantee or turn this test red.
it('routes every element read through an Authorization seam', function (string $class, string $method, string $seam) {
    $reflection = new ReflectionMethod($class, $method);
    $src = implode("\n", array_slice(
        explode("\n", (string) file_get_contents((string) $reflection->getFileName())),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
    expect($src)->toContain("Authorization::{$seam}(");
})->with([
    [EntryTools::class, 'countEntries', 'scopeQuery'],
    [EntryTools::class, 'resolveRef', 'assertCanView'],
    [EntryTools::class, 'previewUrl', 'assertCanView'],
    [CategoryTools::class, 'listCategories', 'scopeQuery'],
]);
