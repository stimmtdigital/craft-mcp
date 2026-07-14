<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;

// Every tool declares its blast radius: dangerous tools are marked
// destructive, everything else is marked read-only and idempotent, so MCP
// clients can gate and cache accordingly. reload_mcp mutates server state
// without being dangerous, hence the single exemption.
it('annotates every non-dangerous tool read-only and idempotent', function () {
    $exempt = ['reload_mcp'];
    $missing = [];

    foreach (glob(__DIR__ . '/../../../src/tools/*.php') as $file) {
        $class = 'stimmt\\craft\\Mcp\\tools\\' . basename($file, '.php');
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            $tools = $method->getAttributes(McpTool::class);
            if ($tools === []) {
                continue;
            }

            $tool = $tools[0]->newInstance();
            $metas = $method->getAttributes(McpToolMeta::class);
            $dangerous = $metas !== [] && $metas[0]->newInstance()->dangerous;

            if ($dangerous || in_array($tool->name, $exempt, true)) {
                continue;
            }

            if ($tool->annotations?->readOnlyHint !== true || $tool->annotations?->idempotentHint !== true) {
                $missing[] = $tool->name;
            }
        }
    }

    expect($missing)->toBe([]);
});
