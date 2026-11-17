<?php

declare(strict_types=1);

use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\capabilities\OperationsTools;

/**
 * The declared surface of the operations tools: developer tools for admins
 * that change nothing.
 */
$tools = static function (): array {
    $found = [];
    foreach ((new ReflectionClass(OperationsTools::class))->getMethods() as $method) {
        $attribute = $method->getAttributes(McpTool::class)[0] ?? null;
        if ($attribute !== null) {
            $found[(string) $attribute->newInstance()->name] = $method;
        }
    }
    ksort($found);

    return $found;
};

describe('OperationsTools', function () use ($tools) {
    it('declares the three tools', function () use ($tools) {
        expect(array_keys($tools()))->toBe(['stimmt_errors_summary', 'stimmt_health', 'stimmt_queue_failures']);
    });

    it('requires an admin for each, never as a dangerous tool', function () use ($tools) {
        foreach ($tools() as $name => $method) {
            expect($method->getAttributes(RequiresAdmin::class))->toHaveCount(1, $name)
                ->and($method->getAttributes(Dangerous::class))->toBe([], $name);
        }
    });

    it('marks each read-only and idempotent', function () use ($tools) {
        foreach ($tools() as $name => $method) {
            $annotations = $method->getAttributes(McpTool::class)[0]->newInstance()->annotations;

            expect($annotations?->readOnlyHint)->toBeTrue($name)->and($annotations?->idempotentHint)->toBeTrue($name);
        }
    });

    // Craft registers its own tools on the same server; a name equal to one of
    // them would shadow it or be shadowed.
    it('uses no name a Craft tool has', function () use ($tools) {
        $craft = [];
        foreach ((array) glob(dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/mcp/capabilities/*.php') as $file) {
            preg_match_all("/name: '([a-z_]+)'/", (string) file_get_contents((string) $file), $matches);
            $craft = [...$craft, ...$matches[1]];
        }

        expect($craft)->not->toBeEmpty()->and(array_intersect(array_keys($tools()), $craft))->toBe([]);
    });
});

describe('OperationsTools::errorsSummary()', function () {
    // Refused before any log is read, so these run without Craft.
    it('refuses hours outside 1 to 720', function (int $hours) {
        expect(fn () => (new OperationsTools())->errorsSummary($hours))
            ->toThrow(ToolCallException::class, "hours must be between 1 and 720, got {$hours}.");
    })->with([[0], [-3], [721]]);

    it('refuses a limit below one', function () {
        expect(fn () => (new OperationsTools())->errorsSummary(24, null, 0))
            ->toThrow(ToolCallException::class, 'limit must be 1 or greater, got 0.');
    });
});
