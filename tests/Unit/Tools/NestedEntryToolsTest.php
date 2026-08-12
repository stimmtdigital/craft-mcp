<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\fieldlayoutelements\CustomField;
use craft\fields\Entries;
use craft\models\EntryType;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;
use stimmt\craft\Mcp\tools\NestedEntryTools;

function nestedEntryTools(): NestedEntryTools {
    return new NestedEntryTools(new Writer(Translator::withDefaults(Layouts::keysWith())));
}

describe('NestedEntryTools structure', function () {
    it('exposes the two nested tools, both dangerous content tools', function (string $method, string $name) {
        $reflection = new ReflectionMethod(NestedEntryTools::class, $method);
        $meta = $reflection->getAttributes(McpToolMeta::class)[0]->newInstance();

        expect($reflection->getAttributes(McpTool::class)[0]->newInstance()->name)->toBe($name)
            ->and($meta->dangerous)->toBeTrue()
            ->and($meta->category)->toBe(ToolCategory::CONTENT);
    })->with([
        ['createNestedEntry', 'create_nested_entry'],
        ['moveNestedEntry', 'move_nested_entry'],
    ]);

    it('marks both tools destructive for MCP clients', function (string $method) {
        $tool = (new ReflectionMethod(NestedEntryTools::class, $method))
            ->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->annotations?->destructiveHint)->toBeTrue();
    })->with([['createNestedEntry'], ['moveNestedEntry']]);

    it('accepts the documented parameters', function (string $method, array $expected) {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(NestedEntryTools::class, $method))->getParameters(),
        );

        expect($params)->toContain(...$expected);
    })->with([
        ['createNestedEntry', ['owner', 'field', 'type', 'title', 'fields', 'site', 'mode', 'position']],
        ['moveNestedEntry', ['id', 'position', 'site', 'mode']],
    ]);
});

describe('input hygiene before any lookup', function () {
    // These guards run before the first Craft::$app access, so they execute
    // behaviorally without a booted application: the deeper rejection paths
    // (revision owners, draft block ids, non-Matrix fields) need element
    // queries and are covered structurally below.
    it('rejects a position below one on create', function () {
        nestedEntryTools()->createNestedEntry(owner: 1, field: 'contentBuilder', type: 'text', position: 0);
    })->throws(ToolCallException::class, 'position');

    it('rejects a position below one on move', function () {
        nestedEntryTools()->moveNestedEntry(id: 1, position: -3);
    })->throws(ToolCallException::class, 'position');

    it('rejects an unknown mode on create', function () {
        nestedEntryTools()->createNestedEntry(owner: 1, field: 'contentBuilder', type: 'text', mode: 'bogus');
    })->throws(ToolCallException::class, 'draft or live');

    it('rejects an unknown mode on move', function () {
        nestedEntryTools()->moveNestedEntry(id: 1, position: 1, mode: 'bogus');
    })->throws(ToolCallException::class, 'draft or live');

    it('rejects malformed fields JSON on create', function () {
        nestedEntryTools()->createNestedEntry(owner: 1, field: 'contentBuilder', type: 'text', fields: '{oops');
    })->throws(ToolCallException::class, 'Invalid JSON');
});

describe('Matrix field resolution', function () {
    beforeEach(function () {
        $this->layout = Layouts::with([
            new CustomField(Layouts::matrix('contentBuilder', [], 12)),
            new CustomField(new Entries(['handle' => 'related'])),
        ]);
        $this->resolve = fn (string $handle) => (new ReflectionMethod(NestedEntryTools::class, 'matrixField'))
            ->invoke(nestedEntryTools(), $this->layout, $handle);
    });

    it('resolves a Matrix field by handle from the owner layout', function () {
        expect(($this->resolve)('contentBuilder')->handle)->toBe('contentBuilder');
    });

    it('lists the available Matrix handles for an unknown handle', function () {
        try {
            ($this->resolve)('nope');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('contentBuilder')->toContain('nope');

            return;
        }

        $this->fail('Expected a ToolCallException for an unknown field handle');
    });

    it('rejects a handle that exists but is not a Matrix field', function () {
        try {
            ($this->resolve)('related');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('related')->toContain('contentBuilder');

            return;
        }

        $this->fail('Expected a ToolCallException for a non-Matrix field handle');
    });
});

describe('block type resolution', function () {
    beforeEach(function () {
        $this->matrix = Layouts::matrix('contentBuilder', [
            new EntryType(['handle' => 'text', 'id' => 1]),
            new EntryType(['handle' => 'quote', 'id' => 2]),
        ], 12);
        $this->resolve = fn (string $handle) => (new ReflectionMethod(NestedEntryTools::class, 'blockType'))
            ->invoke(nestedEntryTools(), $this->matrix, $handle);
    });

    it('resolves an entry type by handle within the field', function () {
        expect(($this->resolve)('quote')->handle)->toBe('quote');
    });

    it('lists the allowed type handles for an unknown type', function () {
        try {
            ($this->resolve)('pullquote');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('text')->toContain('quote')->toContain('pullquote');

            return;
        }

        $this->fail('Expected a ToolCallException for an unknown entry type handle');
    });
});

describe('owner-draft model', function () {
    // The block must never be attached to the canonical owner as a pending
    // draft: Craft's owner-save cleanup (NestedElementManager::
    // deleteOtherNestedElements) hard-deletes unpublished draft blocks whose
    // primary owner is the canonical, so any human CP edit of the owner would
    // silently destroy the agent's pending block. The pending block therefore
    // lives on a draft OF THE OWNER, saved live, exactly like a control panel
    // edit. Exercising the save needs a booted application (the acceptance
    // path runs on a real install); these pin the model's load-bearing
    // choices at the source level.
    it('attaches the block to the resolved target owner and saves it live', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect($source)->toContain("'ownerId' => \$target->id")
            ->and($source)->toContain("'primaryOwnerId' => \$target->id")
            ->and($source)->toContain('WriteMode::Live, $site');
    });

    it('reuses an owner that already is a draft instead of drafting a draft', function () {
        $reflection = new ReflectionMethod(NestedEntryTools::class, 'draftOf');
        $file = (string) file_get_contents($reflection->getFileName());
        $body = implode("\n", array_slice(
            explode("\n", $file),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        expect($body)->toContain('getIsDraft()')
            ->and($body)->toContain('createDraft(');
    });

    it('authorizes against the owner canonical, not the draft', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect(substr_count($source, 'Authorization::assertCanSave($ownerEntry->getCanonical())'))->toBe(2);
    });

    it('rejects revision owners and draft or revision block ids with named errors', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect($source)->toContain('is a revision')
            ->and($source)->toContain('positions belong to the canonical block');
    });

    it('rejects blocks whose field is not a Matrix field', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect($source)->toContain('instanceof Matrix')
            ->and($source)->toContain('not a Matrix field');
    });

    it('places blocks through NestedPosition::move, never raw ownership writes', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect($source)->toContain('NestedPosition::move(')
            ->and($source)->not->toContain('Db::update');
    });

    // Cloning a yii Component detaches its behaviors, and the field query's
    // owner scoping IS a behavior applied at prepare time (Component::
    // __clone), so a cloned query would count blocks across every owner of
    // the field instead of this one.
    it('never clones the owner-scoped field query', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect($source)->not->toContain('clone ');
    });

    it('notifies entry resource subscribers only when the canonical owner changed', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        expect($source)->toContain('getIsDraft()')
            ->and(substr_count($source, 'ResourceChangeNotifier::notifyEntry('))->toBe(1);
    });

    it('returns the owner draft identifiers publish_entry accepts, plus block, state, and position', function () {
        $source = (string) file_get_contents((new ReflectionClass(NestedEntryTools::class))->getFileName());

        foreach (["'blockId'", "'ownerId'", "'draftId'", "'draftElementId'", "'state'", "'position'", "'cpEditUrl'", "'warnings'"] as $key) {
            expect($source)->toContain($key);
        }
    });
});
