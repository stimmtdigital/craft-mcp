<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Unit\Events\Fixtures;

use Mcp\Capability\Attribute\McpTool;

/**
 * Lives in a directory of its own so a test can hand that directory to
 * RegisterToolsEvent::addDiscoveryPath() and assert the class was registered.
 * The file is not named *Test.php, so the test runner does not collect it.
 */
final class DiscoveredTools {
    #[McpTool(name: 'found_by_path', description: 'registered through a discovery path')]
    public function run(): array {
        return [];
    }
}
