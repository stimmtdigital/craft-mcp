<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\ToolAction;
use stimmt\craft\Mcp\services\McpServerFactory;

function decide(bool $otherwiseAllowed, bool $editionAllows, bool $showLocked): ToolAction {
    $method = new ReflectionMethod(McpServerFactory::class, 'decide');

    return $method->invoke(new McpServerFactory(), $otherwiseAllowed, $editionAllows, $showLocked);
}

describe('edition gate decision', function () {
    it('keeps a tool allowed by everything', function () {
        expect(decide(true, true, false))->toBe(ToolAction::Keep)
            ->and(decide(true, true, true))->toBe(ToolAction::Keep);
    });

    it('hides an edition-locked tool by default', function () {
        expect(decide(true, false, false))->toBe(ToolAction::Hide);
    });

    it('locks an edition-locked tool when visibility is on', function () {
        expect(decide(true, false, true))->toBe(ToolAction::Lock);
    });

    it('hides a tool disallowed for a non-edition reason regardless of visibility', function () {
        expect(decide(false, true, true))->toBe(ToolAction::Hide)
            ->and(decide(false, false, true))->toBe(ToolAction::Hide);
    });
});

it('lockTool marks the description, relaxes the schema, and returns the upgrade message', function () {
    $eventDispatcher = new \stimmt\craft\Mcp\support\EventDispatcher();
    $registry = new \Mcp\Capability\Registry($eventDispatcher, new \Psr\Log\NullLogger());

    // A restrictive source schema, so relaxing it to a permissive object is observable.
    $tool = new \Mcp\Schema\Tool(
        name: 'create_entry',
        title: null,
        inputSchema: ['type' => 'object', 'required' => ['section'], 'properties' => ['section' => ['type' => 'string']]],
        description: 'Create an entry.',
        annotations: null,
    );
    $registry->registerTool($tool, static fn (): array => ['created' => true]);

    $factory = new \stimmt\craft\Mcp\services\McpServerFactory();
    (new ReflectionMethod($factory, 'lockTool'))->invoke($factory, $registry, 'create_entry');

    $ref = $registry->getTool('create_entry');
    expect($ref->tool->description)->toStartWith('[Pro]')
        ->and($ref->tool->inputSchema)->toBe(['type' => 'object', 'properties' => [], 'required' => []])
        ->and(($ref->handler)())->toBe(\stimmt\craft\Mcp\enums\Edition::proUpgradeMessage());
});
