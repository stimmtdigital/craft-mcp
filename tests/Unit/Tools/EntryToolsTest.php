<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\capabilities\EntryTools;

/**
 * v2 leaves entry CRUD to Craft's own elements_* tools. What stays here is
 * what Craft does not have: grouped counts, natural key to id, and preview
 * links.
 */
describe('EntryTools structure', function () {
    it('exposes exactly the three v2 tools', function () {
        $names = [];
        foreach ((new ReflectionClass(EntryTools::class))->getMethods() as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $names[$method->getName()] = $attribute->newInstance()->name;
            }
        }

        expect($names)->toBe([
            'countEntries' => 'stimmt_entries_count',
            'resolveRef' => 'stimmt_refs_resolve',
            'previewUrl' => 'stimmt_entries_preview_url',
        ]);
    });

    it('marks every tool read-only', function (string $method) {
        $tool = (new ReflectionMethod(EntryTools::class, $method))->getAttributes(McpTool::class)[0]->newInstance();

        expect($tool->annotations?->readOnlyHint)->toBeTrue();
    })->with([['countEntries'], ['resolveRef'], ['previewUrl']]);
});

describe('stimmt_entries_count', function () {
    it('is registered with the shared filter parameters', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'countEntries'))->getParameters(),
        );

        expect($params)->toContain('groupBy')->toContain('filters')->toContain('relatedTo')->toContain('author')
            ->toContain('updatedAfter')->toContain('updatedBefore')
            ->toContain('createdAfter')->toContain('createdBefore');
    });

    it('declares JSON schemas on the object parameters', function (string $param) {
        $parameters = (new ReflectionMethod(EntryTools::class, 'countEntries'))->getParameters();
        $byName = array_combine(array_map(fn ($p) => $p->getName(), $parameters), $parameters);

        expect($byName[$param]->getAttributes(Schema::class)[0]->newInstance()->type)->toBe('object');
    })->with([['filters'], ['relatedTo']]);

    it('normalizes the any status instead of aborting the query', function () {
        $source = (string) file_get_contents((string) (new ReflectionClass(EntryTools::class))->getFileName());

        expect(substr_count($source, "=== 'any' ? null"))->toBe(1);
    });
});

describe('stimmt_refs_resolve', function () {
    it('takes a type, a key object and an optional site', function () {
        $parameters = (new ReflectionMethod(EntryTools::class, 'resolveRef'))->getParameters();
        $byName = array_combine(array_map(fn ($p) => $p->getName(), $parameters), $parameters);

        expect(array_keys($byName))->toBe(['type', 'key', 'site'])
            ->and($byName['site']->isOptional())->toBeTrue()
            ->and($byName['type']->getAttributes(Schema::class)[0]->newInstance()->enum)
            ->toBe(['entry', 'asset', 'category', 'tag', 'user', 'globalSet']);
    });

    // An unknown type must say which types exist, before any lookup runs.
    it('refuses an unknown element type, listing the ones it knows', function () {
        expect(fn () => (new EntryTools())->resolveRef('product', ['slug' => 'x']))
            ->toThrow(ToolCallException::class, "Unknown type 'product'. Use one of: entry, asset, category, tag, user, globalSet.");
    });
});

describe('stimmt_entries_preview_url', function () {
    it('takes an element id and an optional site', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(EntryTools::class, 'previewUrl'))->getParameters(),
        );

        expect($params)->toBe(['id', 'site']);
    });

    // Drafts are what a person most needs to preview, so the lookup must
    // admit them (and provisional drafts) rather than only canonical entries.
    it('finds drafts as well as canonical entries', function () {
        $source = (string) file_get_contents((string) (new ReflectionClass(EntryTools::class))->getFileName());

        expect($source)->toContain('->drafts(null)->provisionalDrafts(null)');
    });
});
