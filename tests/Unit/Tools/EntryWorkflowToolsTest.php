<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;

describe('EntryWorkflowTools structure', function () {
    it('exposes the four workflow tools, all dangerous', function (string $method, string $name) {
        $reflection = new ReflectionMethod(EntryWorkflowTools::class, $method);

        expect($reflection->getAttributes(McpTool::class)[0]->newInstance()->name)->toBe($name)
            ->and($reflection->getAttributes(McpToolMeta::class)[0]->newInstance()->dangerous)->toBeTrue();
    })->with([
        ['publishEntry', 'publish_entry'],
        ['deleteEntry', 'delete_entry'],
        ['duplicateEntry', 'duplicate_entry'],
        ['copyEntryToSite', 'copy_entry_to_site'],
    ]);
});
