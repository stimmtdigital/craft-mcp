<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\tools\EntryTools;

describe('EntryTools structure', function () {
    it('exposes the seven tools with expected names', function (string $method, string $name) {
        $attributes = (new ReflectionMethod(EntryTools::class, $method))->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->name)->toBe($name);
    })->with([
        ['listEntries', 'list_entries'],
        ['getEntry', 'get_entry'],
        ['createEntry', 'create_entry'],
        ['createNestedEntry', 'create_nested_entry'],
        ['moveNestedEntry', 'move_nested_entry'],
        ['updateEntry', 'update_entry'],
        ['describeEntrySchema', 'describe_entry_schema'],
    ]);

    it('marks the write tools dangerous', function (string $method) {
        $meta = (new ReflectionMethod(EntryTools::class, $method))->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($meta->dangerous)->toBeTrue();
    })->with([['createEntry'], ['createNestedEntry'], ['moveNestedEntry'], ['updateEntry']]);

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

describe('create_nested_entry', function () {
    it('targets a block by owner, field and type rather than by section', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'createNestedEntry'))->getParameters(),
        );

        expect($params)->toContain('owner')->toContain('field')->toContain('type')
            ->and($params)->not->toContain('section');
    });

    it('requires owner, field and type, leaving the rest optional', function (string $name, bool $optional) {
        $byName = [];
        foreach ((new ReflectionMethod(EntryTools::class, 'createNestedEntry'))->getParameters() as $p) {
            $byName[$p->getName()] = $p;
        }

        expect($byName[$name]->isOptional())->toBe($optional);
    })->with([
        ['owner', false],
        ['field', false],
        ['type', false],
        ['title', true],
        ['site', true],
        ['fields', true],
        ['mode', true],
    ]);

    // A nested entry has no section of its own, so Craft's canSave has nothing
    // to inspect on the block itself. The block is written into the owner's
    // content, which makes the owner the element authorization is actually
    // about -- asserting on a bare new Entry (the create_entry approach) would
    // check nothing at all here.
    it('authorizes against the owner entry', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('Authorization::assertCanSave($ownerEntry);');
    });

    // Ownership is what attaches the block to the field. primaryOwnerId must be
    // the canonical id: pointing it at a draft's element id would attach the
    // block to the draft and lose it when the draft is applied.
    it('attaches the block through fieldId and the canonical primaryOwnerId', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain("'fieldId' => \$matrixField->id,")
            ->and($source)->toContain("'primaryOwnerId' => \$ownerEntry->getCanonicalId(),");
    });

    it('rejects an entry type the target field does not allow', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('is not allowed in field');
    });

    it('accepts an optional position, appending when it is omitted', function () {
        $byName = [];
        foreach ((new ReflectionMethod(EntryTools::class, 'createNestedEntry'))->getParameters() as $p) {
            $byName[$p->getName()] = $p;
        }

        expect($byName)->toHaveKey('position')
            ->and($byName['position']->isOptional())->toBeTrue()
            ->and($byName['position']->getDefaultValue())->toBeNull();
    });
});

describe('move_nested_entry', function () {
    it('takes the block id and a target position', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'moveNestedEntry'))->getParameters(),
        );

        expect($params)->toContain('id')->toContain('position');
    });

    // Reordering rewrites the owner's field, so the owner is what may or may
    // not be writable -- the block's own permissions say nothing about it.
    it('authorizes against the owner, not the block', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('Authorization::assertCanSave($ownerEntry);')
            ->and($source)->toContain('is not a block inside a Matrix field');
    });

    it('rejects a position below 1 rather than silently clamping', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('Position must be 1 or greater');
    });

    // Positions past the end are clamped instead of erroring, so "put it last"
    // does not require knowing the count first. Returning the position taken
    // is what makes that unambiguous to the caller.
    it('reports the position actually taken', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain("'position' => \$placed,");
    });

    it('honours mode like the other write tools', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'moveNestedEntry'))->getParameters(),
        );

        expect($params)->toContain('mode');
    });

    // Order is stored per owner, so a drafted reorder has to be a draft of the
    // owner -- there is nothing on the block itself to draft. Without this the
    // tool would be the only write tool that ignores entryWriteMode and
    // rearranges a live page with no review step.
    it('drafts the owner rather than writing the live entry', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('$this->mode($mode) === WriteMode::Draft && !$ownerEntry->getIsDraft()')
            ->and($source)->toContain('createDraft($ownerEntry');
    });

    // The caller needs the owner draft's id to publish it, and publishing is
    // what makes the reorder live -- returning only the block id would leave
    // no way to apply it.
    it('returns the owner draft id and the resulting state', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain("'draftElementId' => \$draft?->id,")
            ->and($source)->toContain("'state' => \$draft === null ? WriteMode::Live->value : WriteMode::Draft->value,");
    });

    // A live reorder changes what the canonical entry renders, so subscribers
    // must hear about it; a drafted one changes nothing live and must not fire.
    it('notifies resource subscribers only on a live reorder', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain("if (\$draft === null) {\n                ResourceChangeNotifier::notifyEntry(\$context, \$ownerEntry->getCanonicalId());");
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
    it('notifies resource subscribers through the shared ResourceChangeNotifier after create_entry, update_entry, create_nested_entry and move_nested_entry', function () {
        $source = (string) file_get_contents((new ReflectionClass(EntryTools::class))->getFileName());

        expect(substr_count($source, 'ResourceChangeNotifier::notifyEntry('))->toBe(4);
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

        expect(substr_count($source, '}, $context);'))->toBe(4);
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
