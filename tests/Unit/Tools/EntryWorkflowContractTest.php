<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
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

/**
 * The refusal a draft or revision id earns from the shared canonical guard.
 * Built from an element rather than looked up, so the wording is testable
 * without an install to look anything up in.
 */
function workflowRefusal(Entry $entry, string $tool): ToolCallException {
    return (new ReflectionMethod(EntryWorkflowTools::class, 'notCanonical'))
        ->invoke((new ReflectionClass(EntryWorkflowTools::class))->newInstanceWithoutConstructor(), $entry, $tool);
}

// list_revisions used to answer total: 0 for an id that has history, because
// revisionOf() on a draft or a revision simply matches nothing. The id it was
// most often asked with is a draftElementId that list_drafts had just handed
// the caller, so "no history" was a confident wrong answer to the most
// realistic workflow there is.
//
// duplicate_entry and copy_entry_to_site had the same shape with a different
// symptom: they resolved with a lookup that excludes drafts and revisions, so
// the same id answered "Entry 3059 not found" while get_entry read it in full
// in the same session. All three now share one guard, which is the point: the
// second copy is where the diagnosis went missing.
describe('the canonical-entry guard', function () {
    // Drafts and revisions have to be admitted by the lookup, or the guard
    // cannot tell "this id is a draft" from "this id does not exist", and it
    // could not name the canonical id either, which is the only actionable
    // half of the answer.
    it('looks the id up in every element state before refusing', function () {
        expect(workflowMethodBody('canonical'))->toContain('Lookup::inAnyState($id, $site)');
    });

    // SiteResolver's house style: say what was wrong AND where to find the
    // right value. Shared with the write guard rather than restated.
    it('points an unknown id at the tool that lists real ones', function () {
        expect(workflowMethodBody('canonical'))->toContain('EntryResolver::missing($id)');
    });

    it('names the state, the canonical id, and the call to make instead', function (string $tool) {
        $message = workflowRefusal(
            new Entry(['id' => 3059, 'siteId' => 1, 'draftId' => 1013, 'canonicalId' => 3029]),
            $tool,
        )->getMessage();

        expect($message)->toContain('Entry 3059 is a draft of entry 3029')
            ->toContain("{$tool} works on the canonical entry")
            ->toContain("Call {$tool} with id 3029");
    })->with([['list_revisions'], ['duplicate_entry'], ['copy_entry_to_site']]);

    it('tells a revision apart from a draft', function () {
        $message = workflowRefusal(
            new Entry(['id' => 3549, 'siteId' => 1, 'revisionId' => 1092, 'canonicalId' => 3545]),
            'duplicate_entry',
        )->getMessage();

        expect($message)->toContain('Entry 3549 is a revision of entry 3545');
    });

    // An unpublished draft is its own canonical, so there is no second id to
    // send the caller to. Saying "ask for {$id}" there would be a loop, and
    // that id is what create_entry hands back in the default draft-first flow.
    it('tells an unpublished draft apart from a draft of something', function () {
        $message = workflowRefusal(new Entry(['id' => 588, 'siteId' => 1, 'draftId' => 225]), 'duplicate_entry')
            ->getMessage();

        expect($message)->toContain('Entry 588 is an unpublished draft')
            ->toContain('has never been a live entry')
            ->toContain('publish_entry makes it one')
            ->toContain('takes this same id')
            ->and($message)->not->toContain('id 588 instead');
    });

    it('is what all three canonical-only tools resolve through', function (string $method, string $tool) {
        expect(workflowMethodBody($method))->toContain("'{$tool}')")
            ->toContain('$this->canonical(')
            ->and(workflowMethodBody($method))->not->toContain('Lookup::canonical($id, SiteResolver::resolve(');
    })->with([
        'revisions' => ['listRevisions', 'list_revisions'],
        'duplicate' => ['duplicateEntry', 'duplicate_entry'],
        'copy' => ['copyEntryToSite', 'copy_entry_to_site'],
    ]);

    it('refuses before the tool acts', function (string $method, string $acts) {
        $body = workflowMethodBody($method);

        expect(strpos($body, '$this->canonical('))->toBeInt()
            ->and(strpos($body, '$this->canonical('))->toBeLessThan((int) strpos($body, $acts));
    })->with([
        'revisions' => ['listRevisions', 'Entry::find()'],
        'duplicate' => ['duplicateEntry', 'duplicateElement('],
        'copy' => ['copyEntryToSite', '$this->writer->update('],
    ]);

    it('says in the list_revisions description that a draft id is refused', function () {
        expect(workflowToolDescription('listRevisions'))->toContain('draftElementId');
    });
});

// The target side of the copy was a second, hand-rolled lookup: it agreed with
// the source lookup only by coincidence, and its miss ("does not exist on
// site") diagnoses a section that is not enabled there and nothing else.
describe('copy_entry_to_site resolves both ends the same way', function () {
    it('reads the target through the shared lookup rather than its own query', function () {
        expect(workflowMethodBody('copyEntryToSite'))
            ->toContain('Lookup::canonical($id, $targetSite)')
            ->and(workflowMethodBody('copyEntryToSite'))->not->toContain('Entry::find()->id($id)');
    });

    it('keeps the per-site miss saying why the entry is not there', function () {
        expect(workflowMethodBody('copyEntryToSite'))
            ->toContain("does not exist on site '{\$toSite}'")
            ->toContain('the section may not be enabled for it');
    });
});

// publish_entry and delete_entry resolved their id with Lookup::withDrafts,
// which excludes revisions, so a revisionElementId came back as "Entry 3306
// not found" in the same session where get_entry 3306 returned the full
// payload and update_entry 3306 named the canonical. The element plainly
// exists; only the lookup refused to see it, which sends an agent hunting for
// a missing id instead of at the canonical one.
describe('publish_entry and delete_entry on a revision id', function () {
    it('resolves the id through the shared write guard', function (string $method, string $tool) {
        expect(workflowMethodBody($method))
            ->toContain("EntryResolver::writable(\$id, SiteResolver::resolve(\$site), '{$tool}')")
            ->and(workflowMethodBody($method))->not->toContain('Lookup::withDrafts($id');
    })->with([
        'publish' => ['publishEntry', 'publish_entry'],
        'delete' => ['deleteEntry', 'delete_entry'],
    ]);

    // Naming the canonical is only possible while the revision is still
    // findable, which is why the shared guard resolves in every state and
    // refuses afterwards; EntryResolverTest owns the diagnosis it produces.
    it('refuses before it acts', function (string $method, string $acts) {
        $body = workflowMethodBody($method);

        expect(strpos($body, 'EntryResolver::writable('))->toBeInt()
            ->and(strpos($body, 'EntryResolver::writable('))->toBeLessThan((int) strpos($body, $acts));
    })->with([
        'publish' => ['publishEntry', '$this->applyDraft('],
        'delete' => ['deleteEntry', 'deleteElement('],
    ]);
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

    // The sentence used to end "so 'nl' already reads the same as 'default'",
    // which is a claim about the whole entry from a tool that only looked at
    // the fields. Observed live with the titles reading "Audit Probe Alpha"
    // and "Audit Probe Alpha Updated", so the sentence was simply false.
    it('claims only what it looked at when there is nothing to copy', function () {
        // The method body still quotes the old sentence in the comment saying
        // why it went, so the check is on the message line itself.
        $message = (string) strstr($this->body, "'message' => \"Nothing to copy");

        expect($message)->toContain('every field already reads the same on')
            ->toContain('Title and slug are per-site and this tool never copies them')
            ->and($message)->not->toContain("already reads the same as '{\$fromSite}'");
    });

    it('makes the same narrower claim in its description', function () {
        $description = workflowToolDescription('copyEntryToSite');

        expect($description)->toContain('its fields already match on both sites')
            ->and($description)->not->toContain('the two sites already read the same');
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
