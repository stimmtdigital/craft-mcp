<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;
use stimmt\craft\Mcp\tools\EntryTools;

describe('EntryTools structure', function () {
    it('exposes the five tools with expected names', function (string $method, string $name) {
        $attributes = (new ReflectionMethod(EntryTools::class, $method))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe($name);
    })->with([
        ['listEntries', 'list_entries'],
        ['getEntry', 'get_entry'],
        ['createEntry', 'create_entry'],
        ['updateEntry', 'update_entry'],
        ['describeEntrySchema', 'describe_entry_schema'],
    ]);

    it('marks the write tools dangerous', function (string $method) {
        $meta = (new ReflectionMethod(EntryTools::class, $method))->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue();
    })->with([['createEntry'], ['updateEntry']]);

    it('accepts site, mode, and parent parameters on the write tools', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'createEntry'))->getParameters(),
        );

        expect($params)->toContain('site')->toContain('mode')->toContain('parent');
    });

    // Craft 5 elements expose setParentId()/parentId; 'newParentId' is not a
    // property, so writes carrying a parent would throw UnknownPropertyException
    // (final-review C1). Behavioral coverage lives in dev/integration-elements.py;
    // this locks the attribute key at the source level.
    it('sets the structure parent through the parentId attribute', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain("\$attributes['parentId'] = \$parentId;")
            ->and($source)->not->toContain('newParentId');
    });
});

describe('list_entries query surface', function () {
    it('accepts the shared filter parameters', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'listEntries'))->getParameters(),
        );

        expect($params)->toContain('filters')->toContain('relatedTo')->toContain('author')
            ->toContain('updatedAfter')->toContain('updatedBefore')
            ->toContain('createdAfter')->toContain('createdBefore');
    });

    it('declares JSON schemas on the object parameters', function (string $param) {
        $parameters = (new ReflectionMethod(EntryTools::class, 'listEntries'))->getParameters();
        $byName = array_combine(array_map(fn ($p) => $p->getName(), $parameters), $parameters);

        expect($byName[$param]->getAttributes(Schema::class))->toHaveCount(1);
    })->with([['filters'], ['relatedTo']]);

    it('is annotated read-only and idempotent', function () {
        $tool = (new ReflectionMethod(EntryTools::class, 'listEntries'))
            ->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->annotations)->toBeInstanceOf(ToolAnnotations::class)
            ->and($tool->annotations->readOnlyHint)->toBeTrue()
            ->and($tool->annotations->idempotentHint)->toBeTrue();
    });

    it('accepts a fields projection parameter with an array schema', function () {
        $parameters = (new ReflectionMethod(EntryTools::class, 'listEntries'))->getParameters();
        $byName = array_combine(array_map(fn ($p) => $p->getName(), $parameters), $parameters);

        $schema = $byName['fields']->getAttributes(Schema::class)[0]->newInstance();

        expect($schema->type)->toBe('array');
    });

});

describe('entry write notifications', function () {
    it('notifies resource subscribers through the shared ResourceChangeNotifier after both create_entry and update_entry', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect(substr_count($source, 'ResourceChangeNotifier::notifyEntry('))->toBe(2);
    });

    // A draft write (the default entryWriteMode) never touches the canonical
    // craft://entries/{section}/{slug} content, so it must not fire a
    // notification for it; only a live write actually changes what that
    // resource serves. Guards the false-positive the reviewer caught: create/
    // update refetching the draft/stale canonical row and pushing regardless
    // of whether canonical content changed.
    it('gates the notification on a live write, not merely a successful one', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect(substr_count($source, '$result->state === WriteMode::Live && $result->elementId !== null'))->toBe(2);
    });

    // The context used to be threaded by hand into the two write tools, and
    // this counted the occurrences in the source. Freshness now builds it
    // from what the SDK injects on every call, so the guarantee covers all 61
    // tools instead of 2 and is no longer a property of this file's text.
    // ConfigRefreshTest owns it.
});

describe('get_entry lookups', function () {
    it('resolves revision ids on id lookups', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('revisions(null)');
    });
});

describe('update_entry conflict detection', function () {
    // The comparison helper runs behaviorally on constructed entries; only
    // the full update round trip needs a booted application. The contract:
    // expectedDateUpdated omitted means unchecked (nothing breaks for
    // existing callers), a matching instant passes regardless of timezone
    // formatting, and a mismatch throws naming both values so the caller
    // re-reads instead of silently overwriting (#36).
    beforeEach(function () {
        $translator = Translator::withDefaults(Layouts::keysWith());
        $this->assert = function (?DateTime $current, ?string $expected) use ($translator): void {
            (new ReflectionMethod(EntryTools::class, 'assertUnchanged'))->invoke(
                new EntryTools(new Reader($translator), new Writer($translator)),
                new Entry(['siteId' => 1, 'id' => 7, 'dateUpdated' => $current]),
                $expected,
            );
        };
    });

    it('accepts the expectedDateUpdated parameter', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'updateEntry'))->getParameters(),
        );

        expect($params)->toContain('expectedDateUpdated');
    });

    it('is a no-op when expectedDateUpdated is omitted', function () {
        ($this->assert)(new DateTime('2026-08-12 10:00:00'), null);

        expect(true)->toBeTrue();
    });

    it('passes when the timestamps name the same instant', function () {
        ($this->assert)(new DateTime('2026-08-12 10:00:00'), '2026-08-12 10:00:00');

        expect(true)->toBeTrue();
    });

    it('passes when the same instant is written in another timezone', function () {
        ($this->assert)(new DateTime('2026-08-12 10:00:00', new DateTimeZone('UTC')), '2026-08-12T12:00:00+02:00');

        expect(true)->toBeTrue();
    });

    it('rejects a stale snapshot naming both values', function () {
        try {
            ($this->assert)(new DateTime('2026-08-12 10:00:00'), '2026-08-11 09:30:00');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('changed since you read it')
                ->toContain('2026-08-12 10:00:00')
                ->toContain('2026-08-11 09:30:00')
                ->toContain('get_entry');

            return;
        }

        $this->fail('Expected a ToolCallException for a stale snapshot');
    });

    it('rejects an unparsable expectedDateUpdated', function () {
        ($this->assert)(new DateTime('2026-08-12 10:00:00'), 'not a date');
    })->throws(ToolCallException::class, 'expectedDateUpdated');

    // The write path itself needs a booted application; this pins that the
    // precondition actually guards updateEntry (behavior of the comparison
    // is covered above).
    it('checks the precondition inside updateEntry before writing', function () {
        $reflection = new ReflectionMethod(EntryTools::class, 'updateEntry');
        $file = (string) file_get_contents($reflection->getFileName());
        $body = implode("\n", array_slice(
            explode("\n", $file),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        $check = strpos($body, '$this->assertUnchanged($entry, $expectedDateUpdated)');
        $write = strpos($body, '$this->writer->update(');

        expect($check)->toBeInt()
            ->and($write)->toBeInt()
            ->and($check)->toBeLessThan($write);
    });
});

describe('count_entries', function () {
    it('is registered read-only with the shared filter parameters', function () {
        $method = new ReflectionMethod(EntryTools::class, 'countEntries');
        $tool = $method->getAttributes(McpTool::class)[0]->newInstance();
        $params = array_map(fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        expect($tool->name)->toBe('count_entries')
            ->and($tool->annotations->readOnlyHint)->toBeTrue()
            ->and($params)->toContain('groupBy')->toContain('filters')->toContain('relatedTo')
            ->toContain('updatedAfter')->toContain('author');
    });

    it('normalizes the any status instead of aborting the query', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect(substr_count($source, "=== 'any' ? null"))->toBe(2);
    });
});
