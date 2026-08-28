<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;

#[RequiresEdition(Edition::Pro)]
class ProToolClass {
    #[McpTool(name: 'fixture_pro_a', description: 'a')]
    public function a(): array {
        return [];
    }

    #[McpTool(name: 'fixture_pro_b', description: 'b')]
    public function b(): array {
        return [];
    }
}
