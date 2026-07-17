<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\attributes\McpToolMeta;
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

    it('threads the RequestContext through to SafeExecution::run() on the write tools', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect(substr_count($source, '}, $context);'))->toBe(2);
    });
});

describe('get_entry lookups', function () {
    it('resolves revision ids on id lookups', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('revisions(null)');
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
