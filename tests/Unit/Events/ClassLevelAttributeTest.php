<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\events\RegisterPromptsEvent;
use stimmt\craft\Mcp\events\RegisterResourcesEvent;
use stimmt\craft\Mcp\events\RegisterToolsEvent;

/**
 * The SDK supports putting an MCP attribute on the CLASS of an invokable and
 * dispatching through __invoke. Our registries only read method-level
 * attributes, so such a class produced no definition, and under the loader an
 * element with no definition is never registered at all: the author following
 * the SDK's own documented pattern got nothing but a debug line.
 */
#[McpTool(name: 'class_level_tool', description: 'declared on the class')]
final class ClassLevelInvokableTool {
    public function __invoke(): array {
        return [];
    }
}

#[McpTool(description: 'takes its name from the class')]
final class ClassLevelUnnamedTool {
    public function __invoke(): array {
        return [];
    }
}

#[McpTool(name: 'class_level_wins', description: 'declared on the class')]
final class ClassLevelWinsTool {
    public function __invoke(): array {
        return [];
    }

    #[McpTool(name: 'never_reached', description: 'the class-level attribute short-circuits this')]
    public function other(): array {
        return [];
    }
}

#[McpTool(name: 'class_level_ignored', description: 'no __invoke to dispatch through')]
final class ClassLevelWithoutInvokeTool {
    #[McpTool(name: 'method_level_tool', description: 'the only dispatchable declaration')]
    public function run(): array {
        return [];
    }
}

#[McpPrompt(name: 'class_level_prompt', description: 'declared on the class')]
final class ClassLevelInvokablePrompt {
    public function __invoke(): array {
        return [];
    }
}

#[McpResource(uri: 'craft://fixtures/class-level', description: 'declared on the class')]
final class ClassLevelInvokableResource {
    public function __invoke(): string {
        return '';
    }
}

#[McpResourceTemplate(uriTemplate: 'craft://fixtures/{id}', description: 'declared on the class')]
final class ClassLevelInvokableTemplate {
    public function __invoke(string $id): string {
        return $id;
    }
}

it('registers an invokable tool declared by a class-level attribute', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(ClassLevelInvokableTool::class, 'test');

    $definitions = $event->getDefinitions();

    expect(array_keys($definitions))->toBe(['class_level_tool'])
        ->and($definitions['class_level_tool']->method)->toBe('__invoke')
        ->and($definitions['class_level_tool']->class)->toBe(ClassLevelInvokableTool::class)
        ->and($event->getErrors())->toBe([]);
});

it('names a class-level tool after the class when the attribute declares none', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(ClassLevelUnnamedTool::class, 'test');

    // Discoverer.php: $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName)
    expect(array_keys($event->getDefinitions()))->toBe(['ClassLevelUnnamedTool']);
});

it('lets a class-level attribute short-circuit the method sweep, as the SDK does', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(ClassLevelWinsTool::class, 'test');

    expect(array_keys($event->getDefinitions()))->toBe(['class_level_wins']);
});

it('falls back to the method sweep when there is no __invoke to dispatch through', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(ClassLevelWithoutInvokeTool::class, 'test');

    // The class-level attribute is unreachable without an __invoke, exactly as
    // in the SDK, so the method-level declaration is the one that counts.
    expect(array_keys($event->getDefinitions()))->toBe(['method_level_tool'])
        ->and($event->getErrors())->toBe([]);
});

it('registers an invokable prompt declared by a class-level attribute', function (): void {
    $event = new RegisterPromptsEvent();
    $event->addPrompt(ClassLevelInvokablePrompt::class, 'test');

    $definitions = $event->getDefinitions();

    expect(array_keys($definitions))->toBe(['class_level_prompt'])
        ->and($definitions['class_level_prompt']->method)->toBe('__invoke')
        ->and($event->getErrors())->toBe([]);
});

it('registers an invokable resource declared by a class-level attribute', function (): void {
    $event = new RegisterResourcesEvent();
    $event->addResource(ClassLevelInvokableResource::class, 'test');

    $definitions = $event->getDefinitions();

    // Static resources are keyed by URI; the name falls back to the class.
    expect(array_keys($definitions))->toBe(['craft://fixtures/class-level'])
        ->and($definitions['craft://fixtures/class-level']->name)->toBe('ClassLevelInvokableResource')
        ->and($definitions['craft://fixtures/class-level']->method)->toBe('__invoke')
        ->and($event->getErrors())->toBe([]);
});

it('registers an invokable resource template declared by a class-level attribute', function (): void {
    $event = new RegisterResourcesEvent();
    $event->addResource(ClassLevelInvokableTemplate::class, 'test');

    $definitions = $event->getDefinitions();

    // Templates are keyed by name, not by URI template.
    expect(array_keys($definitions))->toBe(['ClassLevelInvokableTemplate'])
        ->and($definitions['ClassLevelInvokableTemplate']->uri)->toBe('craft://fixtures/{id}')
        ->and($definitions['ClassLevelInvokableTemplate']->isTemplate)->toBeTrue()
        ->and($event->getErrors())->toBe([]);
});
