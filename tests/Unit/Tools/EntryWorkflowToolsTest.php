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

describe('list_revisions', function () {
    it('is registered read-only with id, site, and pagination parameters', function () {
        $method = new ReflectionMethod(EntryWorkflowTools::class, 'listRevisions');
        $tool = $method->getAttributes(McpTool::class)[0]->newInstance();
        $params = array_map(fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        expect($tool->name)->toBe('list_revisions')
            ->and($tool->annotations->readOnlyHint)->toBeTrue()
            ->and($params)->toContain('id')->toContain('site')->toContain('limit')->toContain('offset');
    });

    it('summarizes revisions through the RevisionBehavior guard', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryWorkflowTools::class))->getFileName());

        expect($source)->toContain('RevisionBehavior')
            ->and($source)->toContain('revisionOf(');
    });
});
