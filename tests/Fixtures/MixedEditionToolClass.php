<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\enums\Edition;

#[RequiresEdition(Edition::Pro)]
class MixedEditionToolClass {
    #[McpTool(name: 'fixture_mixed_free', description: 'free')]
    #[RequiresEdition(Edition::Standard)]
    public function free(): array {
        return [];
    }

    #[McpTool(name: 'fixture_mixed_pro', description: 'pro')]
    public function pro(): array {
        return [];
    }
}
