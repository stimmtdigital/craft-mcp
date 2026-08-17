<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\events\RegisterToolsEvent;

/**
 * Two ways our attribute scanning diverged from the SDK's, both of which ended
 * with a tool the SDK had registered being swept back out by our own filter, or
 * advertised under a name no client can call.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class HouseTool extends McpTool {
}

final class UnnamedTools {
    #[McpTool(description: 'takes its name from the method')]
    public function inferredName(): array {
        return [];
    }
}

final class SubclassedTools {
    #[HouseTool(name: 'house_tool', description: 'declared with a subclassed attribute')]
    public function run(): array {
        return [];
    }
}

it('falls back to the method name when the attribute declares none', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(UnnamedTools::class, 'test');

    // The SDK's own discoverer does exactly this. Ours used an empty string,
    // which the registry then rejected as invalid and registered anyway under
    // the key "", so the tool reached tools/list unnamed and uncallable.
    expect(array_keys($event->getDefinitions()))->toBe(['inferredName'])
        ->and($event->getErrors())->toBe([]);
});

it('discovers an attribute that subclasses McpTool', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(SubclassedTools::class, 'test');

    // McpTool is not final, so subclassing it to carry house metadata is legal
    // and the SDK finds it through IS_INSTANCEOF. Without that flag we found
    // nothing, and our deny-by-default sweep then removed the tool the SDK had
    // registered, leaving only a debug-level trace.
    expect(array_keys($event->getDefinitions()))->toBe(['house_tool'])
        ->and($event->getErrors())->toBe([]);
});
