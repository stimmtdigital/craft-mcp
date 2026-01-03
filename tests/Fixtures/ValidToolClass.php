<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * A valid tool class for testing registration.
 */
class ValidToolClass {
    #[McpTool(name: 'test_tool', description: 'A test tool')]
    public function testTool(): array {
        return ['success' => true];
    }

    #[McpTool(name: 'another_tool', description: 'Another test tool')]
    public function anotherTool(string $param): array {
        return ['param' => $param];
    }
}
