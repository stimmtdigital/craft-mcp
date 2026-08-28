<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;

/**
 * Body of one method of the tool class, for the assertions that are about
 * where a call sits rather than what it returns. Behavior here needs a real
 * Craft install, which the unit suite does not have.
 */
function workflowMethodBody(string $method): string {
    $reflection = new ReflectionMethod(EntryWorkflowTools::class, $method);
    $lines = explode("\n", (string) file_get_contents($reflection->getFileName()));

    return implode("\n", array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

function workflowToolDescription(string $method): string {
    return (new ReflectionMethod(EntryWorkflowTools::class, $method))
        ->getAttributes(McpTool::class)[0]
        ->newInstance()
        ->description;
}

// list_revisions used to answer total: 0 for an id that has history, because
// revisionOf() on a draft or a revision simply matches nothing. The id it was
// most often asked with is a draftElementId that list_drafts had just handed
// the caller, so "no history" was a confident wrong answer to the most
// realistic workflow there is.
describe('list_revisions refuses an id that is not canonical', function () {
    beforeEach(function () {
        $this->body = workflowMethodBody('assertCanonical');
    });

    it('resolves the id before it builds the revision query', function () {
        $listing = workflowMethodBody('listRevisions');

        expect(strpos($listing, '$this->assertCanonical('))->toBeInt()
            ->and(strpos($listing, '$this->assertCanonical('))->toBeLessThan(strpos($listing, 'Entry::find()'));
    });

    // Drafts and revisions have to be admitted by the lookup, or the check
    // cannot tell "this id is a draft" from "this id does not exist" and both
    // callers get the same unhelpful miss.
    it('looks the id up in every element state', function () {
        expect($this->body)->toContain('Lookup::inAnyState($id, $site)');
    });

    it('names the canonical id to ask for instead', function () {
        expect($this->body)->toContain('is a {$state} of entry {$canonicalId}')
            ->and($this->body)->toContain('Call list_revisions with id {$canonicalId}');
    });

    // An unpublished draft is its own canonical, so there is no second id to
    // send the caller to. Saying "ask for {$id}" there would be a loop.
    it('tells an unpublished draft apart from a draft of something', function () {
        expect($this->body)->toContain('if ($canonicalId === $id)')
            ->and($this->body)->toContain('has never been a live entry');
    });

    // SiteResolver's house style: say what was wrong AND where to find the
    // right value.
    it('points an unknown id at the tool that lists real ones', function () {
        expect($this->body)->toContain('not found. Use list_entries');
    });

    it('says in its description that a draft id is refused', function () {
        expect(workflowToolDescription('listRevisions'))->toContain('draftElementId');
    });
});

// copy_entry_to_site claimed to "copy field values" while copying every field,
// translatable or not. A field Craft does not treat as translatable already
// holds one value shared by every site, so copying it states what was already
// true and costs a full re-save of the target's field value for nothing.
describe('copy_entry_to_site copies only what a site can hold separately', function () {
    beforeEach(function () {
        $this->body = workflowMethodBody('copyEntryToSite');
        $this->filter = workflowMethodBody('perSiteFields');
    });

    it('filters the payload on the field\'s own translatability', function () {
        expect($this->filter)->toContain('getIsTranslatable($target) === true');
    });

    it('filters before it writes', function () {
        expect(strpos($this->body, '$this->perSiteFields('))
            ->toBeLessThan(strpos($this->body, '$this->writer->update('));
    });

    it('names the handles it copied', function () {
        expect($this->body)->toContain('\'copiedFields\' => array_keys($fields)');
    });

    // Success with an empty list and a sentence saying why, not a draft that
    // reviews as a change and is not one.
    it('writes no draft at all when nothing is per-site', function () {
        expect($this->body)->toContain('if ($fields === []) {')
            ->and($this->body)->toContain("'copiedFields' => [],")
            ->and($this->body)->toContain('Nothing to copy');
    });

    it('says in its description that shared fields are left alone', function () {
        expect(workflowToolDescription('copyEntryToSite'))
            ->toContain('SEPARATELY PER SITE')
            ->toContain('translatable');
    });
});

// Craft places a duplicate immediately after its source, which in a structure
// section means under the same parent; an unpublished draft is its own
// canonical, so the copy is placed the moment it is made. Nothing in the
// response said so, which is exactly how a copy that DID keep its place reads
// as one that quietly lost it.
describe('duplicate_entry reports where the copy landed', function () {
    it('returns the structure parent alongside the entry', function () {
        expect(workflowMethodBody('duplicateEntry'))
            ->toContain("'parent' => \$this->structureParent(\$duplicate)");
    });

    // Structures::moveAfter() writes the placement straight into
    // structureelements, so the element duplicateElement() returns carries no
    // lft/rgt and reports no parent for a copy that has one. Asking the clone
    // would have this key state the very thing it exists to disprove.
    it('reads the placement back instead of asking the returned clone', function () {
        expect(workflowMethodBody('structureParent'))
            ->toContain('Lookup::withDrafts((int) $entry->id, $entry->getSite())')
            ->toContain('$placed->structureId === null');
    });

    it('says in its description that the copy keeps its place', function () {
        expect(workflowToolDescription('duplicateEntry'))->toContain('same parent');
    });
});
