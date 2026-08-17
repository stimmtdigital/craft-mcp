<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\contracts\ConditionalProvider;
use stimmt\craft\Mcp\contracts\ConditionalToolProvider;
use stimmt\craft\Mcp\events\RegisterToolsEvent;

/**
 * The tools event used to test the deprecated subinterface, so a class
 * implementing ConditionalProvider directly had its availability check ignored
 * and its tools registered regardless, while the prompt and resource events
 * honoured the same interface correctly.
 *
 * Each fixture carries a real tool method, because validation runs before the
 * availability gate: a class with no tools is rejected earlier and would never
 * prove anything about the gate.
 */
final class ModernUnavailableTools implements ConditionalProvider {
    public static function isAvailable(): bool {
        return false;
    }

    #[McpTool(name: 'modern_unavailable', description: 'never registered')]
    public function run(): array {
        return [];
    }
}

final class DeprecatedUnavailableTools implements ConditionalToolProvider {
    public static function isAvailable(): bool {
        return false;
    }

    #[McpTool(name: 'deprecated_unavailable', description: 'never registered')]
    public function run(): array {
        return [];
    }
}

final class ModernAvailableTools implements ConditionalProvider {
    public static function isAvailable(): bool {
        return true;
    }

    #[McpTool(name: 'modern_available', description: 'registered')]
    public function run(): array {
        return [];
    }
}

it('skips a class implementing the current interface when it reports unavailable', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(ModernUnavailableTools::class, 'test');

    expect($event->getDefinitions())->toBe([])
        ->and($event->getErrors())->toBe([]);
});

it('still skips a class implementing the deprecated interface', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(DeprecatedUnavailableTools::class, 'test');

    expect($event->getDefinitions())->toBe([])
        ->and($event->getErrors())->toBe([]);
});

it('registers a class that reports available', function (): void {
    $event = new RegisterToolsEvent();
    $event->addTool(ModernAvailableTools::class, 'test');

    $names = array_map(static fn (object $definition): string => $definition->name, $event->getDefinitions());

    expect($names)->toBe(['modern_available' => 'modern_available'])
        ->and($event->getErrors())->toBe([]);
});
