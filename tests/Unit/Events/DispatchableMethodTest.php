<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\events\RegisterToolsEvent;

/**
 * We iterated getMethods(IS_PUBLIC), which includes inherited and static ones.
 * The SDK's discoverer dispatches neither, so such a tool was advertised by
 * list_mcp_tools and then answered METHOD_NOT_FOUND when a client called it.
 */
final class StaticOnlyTools {
    #[McpTool(name: 'static_tool', description: 'the SDK never dispatches a static method')]
    public static function run(): array {
        return [];
    }
}

class SharedToolsBase {
    #[McpTool(name: 'inherited_tool', description: 'declared on the base class')]
    public function inherited(): array {
        return [];
    }
}

final class SharedToolsChild extends SharedToolsBase {
    #[McpTool(name: 'own_tool', description: 'declared on the child class')]
    public function own(): array {
        return [];
    }
}

final class MagicInvokeTools {
    #[McpTool(name: 'invoke_method_level', description: 'a method-level attribute on __invoke')]
    public function __invoke(): array {
        return [];
    }
}

it('skips a static method carrying the attribute', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(StaticOnlyTools::class, 'test');

    // Discoverer.php skips isStatic(), so registering this would advertise a
    // tool the server has no handler for.
    expect($event->getDefinitions())->toBe([])
        ->and($event->getErrors())->toHaveCount(1)
        ->and($event->getErrors()[0])->toContain('McpTool');
});

it('skips an inherited method and keeps the one the class declares itself', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(SharedToolsChild::class, 'test');

    // Discoverer.php: $method->getDeclaringClass()->getName() !== $reflectionClass->getName()
    expect(array_keys($event->getDefinitions()))->toBe(['own_tool']);
});

it('still registers the base class when the base is what was registered', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(SharedToolsBase::class, 'test');

    expect(array_keys($event->getDefinitions()))->toBe(['inherited_tool'])
        ->and($event->getErrors())->toBe([]);
});

it('skips __invoke when only the method carries the attribute', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(MagicInvokeTools::class, 'test');

    // The SDK reaches __invoke only through a class-level attribute. Mirroring
    // that turns a phantom tool into a visible registration error.
    expect($event->getDefinitions())->toBe([])
        ->and($event->getErrors())->toHaveCount(1);
});
