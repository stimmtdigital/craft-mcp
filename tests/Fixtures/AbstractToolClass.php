<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * An abstract tool class - cannot be instantiated.
 */
abstract class AbstractToolClass {
    #[McpTool(name: 'abstract_tool', description: 'An abstract tool')]
    public function abstractTool(): array {
        return ['success' => true];
    }
}
