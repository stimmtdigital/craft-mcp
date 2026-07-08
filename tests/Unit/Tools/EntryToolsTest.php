<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
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
});
