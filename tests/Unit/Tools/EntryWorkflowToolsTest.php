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

    // Shape-level (behavior needs a Craft app; dev/integration-elements.py is
    // the acceptance test): a canonical id must resolve to its single pending
    // non-provisional draft, fail loudly on several, and refuse a no-op
    // publish (final-review C3).
    it('publishes a canonical id through its pending draft lookup', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryWorkflowTools::class))->getFileName());

        expect($source)->toContain('->draftOf(')
            ->and($source)->toContain('->provisionalDrafts(false)')
            ->and($source)->toContain('multiple pending drafts')
            ->and($source)->toContain('nothing to publish');
    });
});
