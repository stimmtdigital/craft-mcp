<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use stimmt\craft\Mcp\elements\query\Projection;
use stimmt\craft\Mcp\tools\EntryTools;

/**
 * The refusals the entry tools used to answer with a plausible wrong result
 * instead: one of several matching entries, a write into frozen history, "0"
 * for a section that does not exist, and a page window nothing honoured.
 *
 * Each guard is invoked directly. None of them reads anything off the
 * instance, so the tools object is built without its constructor rather than
 * wired to services a unit test has no Craft application to boot.
 *
 * Helpers are closures because Pest shares one global function namespace
 * across the suite, and the source-reading helpers here would collide with
 * the ones EntryToolsTest already keeps for itself.
 */

$guard = static fn (string $method, array $arguments): mixed => (new ReflectionMethod(EntryTools::class, $method))
    ->invokeArgs((new ReflectionClass(EntryTools::class))->newInstanceWithoutConstructor(), $arguments);

$entryToolsSource = static fn (): string => (string) file_get_contents(
    (string) (new ReflectionClass(EntryTools::class))->getFileName(),
);

/** The body of one method, for the assertions that are about ordering. */
$methodBody = static function (string $method) use ($entryToolsSource): string {
    $reflection = new ReflectionMethod(EntryTools::class, $method);

    return implode("\n", array_slice(
        explode("\n", $entryToolsSource()),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
};

describe('get_entry on an address that matches several entries', function () use ($guard, $entryToolsSource) {
    // A slug is unique per site, not per section, so in a structure section
    // the same slug under two parents is ordinary content modelling. ->one()
    // handed back whichever row came first with an empty warnings list, which
    // is indistinguishable from a lookup that was actually unique.
    it('stops the lookup at two rows instead of returning the first', function () use ($entryToolsSource) {
        expect($entryToolsSource())->toContain('$query->limit(2)->all()')
            ->and($entryToolsSource())->not->toContain('return $query->one();');
    });

    it('reports the several-matches case in Resolution\'s own words', function () use ($guard) {
        $message = $guard('ambiguousSlug', ['integration-child', 'pages'])->getMessage();

        expect($message)->toContain('matches more than one entry')
            ->toContain("Slug 'integration-child'")
            ->toContain("in section 'pages'")
            ->toContain('unique per site, not per section')
            ->toContain('list_entries');
    });

    it('tells a section-less lookup to narrow by section before falling back to the id', function () use ($guard) {
        $message = $guard('ambiguousSlug', ['integration-child', null])->getMessage();

        expect($message)->toContain('Pass section to narrow the lookup')
            ->and($message)->not->toContain('in section');
    });
});

describe('update_entry on a revision', function () use ($methodBody) {
    // Revisions stay findable on purpose: a blind miss could not name the id
    // that does work. Reads keep working on them, which is what history is
    // for; only the write path refuses.
    //
    // The refusal itself moved to EntryResolver, which publish_entry and
    // delete_entry now share (they answered "not found" for an id get_entry
    // reads in full); EntryResolverTest owns its wording. What stays here is
    // that update_entry asks it, and asks it in time.
    it('asks the shared guard rather than keeping a copy', function () use ($methodBody) {
        expect($methodBody('updateEntry'))
            ->toContain("EntryResolver::assertWritable(\$entry, 'update_entry')");
    });

    // Ordering is the whole point: a revision rejected after the write would
    // still have written. Behaviour of the write itself needs a booted
    // application, so only the ordering is pinned here.
    it('guards before anything is written', function () use ($methodBody) {
        $body = $methodBody('updateEntry');

        expect(strpos($body, 'EntryResolver::assertWritable('))->toBeInt()
            ->and(strpos($body, 'EntryResolver::assertWritable('))
            ->toBeLessThan((int) strpos($body, '$this->writer->update('));
    });
});

describe('unknown section, type and status', function () use ($entryToolsSource) {
    // "How many entries in section X" answering 0 is a wrong answer when the
    // truthful one is "there is no X". describe_entry_schema always refused;
    // list_entries and count_entries are the siblings that disagreed.
    //
    // The refusals themselves moved to HandleResolver, which every surface
    // asking about a section handle now shares; HandleResolverTest owns their
    // wording. What stays here is that both read tools ask at all.
    it('validates the scope in both read tools', function () use ($entryToolsSource) {
        expect(substr_count($entryToolsSource(), '$this->assertScope($section, $type, $status);'))->toBe(2);
    });

    it('leaves the handle vocabulary to the shared resolver', function () use ($entryToolsSource) {
        expect($entryToolsSource())->toContain('HandleResolver::section($section)')
            ->and($entryToolsSource())->not->toContain('getSectionByHandle');
    });
});

describe('limit and offset outside their range', function () use ($methodBody) {
    // One rule for all three, because they were three different unstated
    // behaviours: a negative limit dropped the LIMIT clause and returned the
    // whole section, 0 returned nothing, and a negative offset was ignored.
    // Refused rather than clamped, because the response echoes both values
    // back and a silent correction reads as a value that was honoured.
    //
    // The rule itself is Window's now, and it is swept across every tool
    // taking the same two parameters by WindowSurfaceTest, which is what the
    // per-tool copy could not be. Here: that list_entries asks for it, and
    // still publishes the range a client reads before calling.
    it('asks the shared guard before it builds the query', function () use ($methodBody) {
        $body = $methodBody('listEntries');

        expect(strpos($body, 'Window::assert($limit, $offset)'))->toBeInt()
            ->and(strpos($body, 'Window::assert($limit, $offset)'))
            ->toBeLessThan((int) strpos($body, 'Entry::find()'));
    });

    it('publishes the range in the schema, so a client can refuse it first', function () {
        $properties = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new ReflectionMethod(EntryTools::class, 'listEntries'))['properties'];

        expect($properties['limit'])->toMatchArray(['type' => 'integer', 'default' => 20, 'minimum' => 1])
            ->and($properties['offset'])->toMatchArray(['type' => 'integer', 'default' => 0, 'minimum' => 0]);
    });

    // The hint is specific to this tool, so it survives the move to a shared
    // description: count_entries is the answer to "I only wanted the total".
    it('still points at count_entries for a total without rows', function () {
        $properties = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new ReflectionMethod(EntryTools::class, 'listEntries'))['properties'];

        expect($properties['limit']['description'])->toContain('count_entries');
    });
});

describe('the fields projection', function () use ($entryToolsSource) {
    // The parameter description promises id and title are included anyway,
    // while Projection's whitelist has an entry for neither, so the
    // description's own example (id, title, slug) came back as "Unknown
    // projection field 'id'". Honouring that promise belongs to Projection,
    // which owns both the whitelist and the two names it always writes; this
    // only pins that list_entries hands the caller's fields straight to it
    // rather than growing a second, drifting copy of the same rule.
    it('leaves the whitelist to Projection instead of pre-filtering it', function () use ($entryToolsSource) {
        expect(array_intersect(['id', 'title'], Projection::ATTRIBUTES))->toBe([])
            ->and($entryToolsSource())->toContain('$this->projection->row($entry, $fields, $site)')
            ->and($entryToolsSource())->not->toContain('array_diff($fields');
    });
});
