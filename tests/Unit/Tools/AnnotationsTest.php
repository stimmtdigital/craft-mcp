<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\attributes\RequiresEdition;

// Every tool declares its blast radius: Craft derives the OAuth scope from
// readOnlyHint (mcp:read versus mcp:write), and clients gate and cache on the
// hints. Everything that is neither #[Dangerous] nor a Pro write is a read,
// so it must say read-only and idempotent. stimmt_entries_preview_url is the
// one exemption: it is read-only but mints a fresh preview token per call.
it('annotates every tool that is neither dangerous nor Pro read-only and idempotent', function () {
    $exempt = ['stimmt_entries_preview_url'];
    $missing = [];

    foreach ((array) glob(__DIR__ . '/../../../src/capabilities/*.php') as $file) {
        $class = 'stimmt\\craft\\Mcp\\capabilities\\' . basename((string) $file, '.php');
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $tools = $method->getAttributes(McpTool::class);
            if ($tools === []) {
                continue;
            }

            $tool = $tools[0]->newInstance();
            $special = $method->getAttributes(Dangerous::class) !== [] || $method->getAttributes(RequiresEdition::class) !== [];

            if ($special || in_array($tool->name, $exempt, true)) {
                continue;
            }

            if ($tool->annotations?->readOnlyHint !== true || $tool->annotations?->idempotentHint !== true) {
                $missing[] = $tool->name;
            }
        }
    }

    expect($missing)->toBe([]);
});
