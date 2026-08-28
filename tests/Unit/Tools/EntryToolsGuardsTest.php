<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Exception\ToolCallException;
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

describe('update_entry on a revision', function () use ($guard, $methodBody) {
    // Revisions stay findable on purpose: a blind miss could not name the id
    // that does work. Reads keep working on them, which is what history is
    // for; only the write path refuses.
    it('refuses a revision id and names the canonical entry to write instead', function () use ($guard) {
        $revision = new Entry(['id' => 3040, 'revisionId' => 9, 'canonicalId' => 3011, 'siteId' => 1]);

        expect(fn () => $guard('assertWritable', [$revision]))
            ->toThrow(ToolCallException::class, 'Entry 3040 is a revision of entry 3011');
    });

    it('points the caller at the id that works', function () use ($guard) {
        $revision = new Entry(['id' => 3040, 'revisionId' => 9, 'canonicalId' => 3011, 'siteId' => 1]);

        try {
            $guard('assertWritable', [$revision]);
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('frozen history')
                ->toContain('Call update_entry with id 3011');

            return;
        }

        throw new RuntimeException('Expected a ToolCallException for a revision id');
    });

    it('lets a canonical entry and a draft through', function (array $config) use ($guard) {
        $guard('assertWritable', [new Entry($config)]);

        expect(true)->toBeTrue();
    })->with([
        'canonical' => [['id' => 3011, 'siteId' => 1]],
        'draft' => [['id' => 3099, 'draftId' => 4, 'canonicalId' => 3011, 'siteId' => 1]],
    ]);

    // Ordering is the whole point: a revision rejected after the write would
    // still have written. Behaviour of the write itself needs a booted
    // application, so only the ordering is pinned here.
    it('guards before anything is written', function () use ($methodBody) {
        $body = $methodBody('updateEntry');

        expect(strpos($body, '$this->assertWritable($entry)'))->toBeInt()
            ->and(strpos($body, '$this->assertWritable($entry)'))
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

describe('limit and offset outside their range', function () use ($guard) {
    // One rule for all three, because they were three different unstated
    // behaviours: a negative limit dropped the LIMIT clause and returned the
    // whole section, 0 returned nothing, and a negative offset was ignored.
    // Refused rather than clamped, because the response echoes both values
    // back and a silent correction reads as a value that was honoured.
    it('refuses a limit below one and points at count_entries', function (int $limit) use ($guard) {
        expect(fn () => $guard('assertWindow', [$limit, 0]))
            ->toThrow(ToolCallException::class, 'limit must be 1 or greater');
    })->with([[0], [-5]]);

    it('refuses a negative offset', function () use ($guard) {
        expect(fn () => $guard('assertWindow', [20, -10]))
            ->toThrow(ToolCallException::class, 'offset must be 0 or greater, got -10');
    });

    it('accepts the defaults and the smallest usable page', function (int $limit, int $offset) use ($guard) {
        $guard('assertWindow', [$limit, $offset]);

        expect(true)->toBeTrue();
    })->with([[20, 0], [1, 0], [500, 1000]]);

    it('publishes the range in the schema, so a client can refuse it first', function () {
        $properties = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new ReflectionMethod(EntryTools::class, 'listEntries'))['properties'];

        expect($properties['limit'])->toMatchArray(['type' => 'integer', 'default' => 20, 'minimum' => 1])
            ->and($properties['offset'])->toMatchArray(['type' => 'integer', 'default' => 0, 'minimum' => 0]);
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
