<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\EntryResolver;

/**
 * The refusal a write owes an id that names frozen history.
 *
 * update_entry had learned it, publish_entry and delete_entry had not: they
 * resolved the id with a query that excludes revisions, so `publish_entry
 * 3306` answered "Entry 3306 not found" in the same session where `get_entry
 * 3306` returned the full payload. One rule in one place now, and the tool to
 * call next is the only part that varies.
 *
 * The guards are invoked directly; a booted Craft application is what the
 * lookup half would need, so what is pinned here is the diagnosis.
 */
$revision = static fn (): Entry => new Entry([
    'id' => 3306,
    'revisionId' => 9,
    'canonicalId' => 3285,
    'siteId' => 1,
]);

describe('a revision id handed to a write', function () use ($revision) {
    it('is refused by name, not reported missing', function (string $tool) use ($revision) {
        expect(fn () => EntryResolver::assertWritable($revision(), $tool))
            ->toThrow(ToolCallException::class, 'Entry 3306 is a revision of entry 3285');
    })->with([['update_entry'], ['publish_entry'], ['delete_entry']]);

    it('names the canonical id and the call that works', function (string $tool) use ($revision) {
        try {
            EntryResolver::assertWritable($revision(), $tool);
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('frozen history')
                ->toContain("Call {$tool} with id 3285 instead");

            return;
        }

        throw new RuntimeException("Expected a ToolCallException for a revision id on {$tool}");
    })->with([['update_entry'], ['publish_entry'], ['delete_entry']]);

    // Reads keep working on a revision, which is what history is for, and a
    // draft is the element the default write flow produces. Only history is
    // refused.
    it('lets a canonical entry and a draft through', function (array $config) {
        EntryResolver::assertWritable(new Entry($config), 'update_entry');

        expect(true)->toBeTrue();
    })->with([
        'canonical' => [['id' => 3285, 'siteId' => 1]],
        'draft' => [['id' => 3299, 'draftId' => 4, 'canonicalId' => 3285, 'siteId' => 1]],
    ]);
});

describe('an id that names nothing', function () {
    // HandleResolver's and SiteResolver's house style: say what was wrong AND
    // where the right value comes from. The two write tools said only "Entry
    // 3306 not found".
    it('says where a real id comes from', function () {
        expect(EntryResolver::missing(9999)->getMessage())
            ->toBe('Entry 9999 not found. Use list_entries to find an entry id.');
    });
});
