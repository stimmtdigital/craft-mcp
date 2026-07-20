<?php

declare(strict_types=1);

use stimmt\craft\Mcp\enums\ToolAction;
use stimmt\craft\Mcp\services\McpServerFactory;

function decide(bool $otherwiseAllowed, bool $editionAllows, bool $showLocked): ToolAction {
    $method = new ReflectionMethod(McpServerFactory::class, 'decide');

    return $method->invoke(null, $otherwiseAllowed, $editionAllows, $showLocked);
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
