<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\tools\McpTools;

// list_mcp_tools advertises tools a scoped token may not actually be allowed
// to call: the privileged (install-introspection) ones are hidden from
// non-admin readonly/content connections by the factory. Reporting the flag
// keeps the listing honest about why a listed tool can be refused.
it('carries the privileged flag on the definitions the listing is built from', function () {
    $definitions = Mcp::getToolRegistry()->getDefinitions();

    expect($definitions['read_logs']->privileged)->toBeTrue()
        ->and($definitions['get_config']->privileged)->toBeTrue()
        ->and($definitions['list_entries']->privileged)->toBeFalse()
        ->and($definitions['get_entry']->privileged)->toBeFalse();
});

// listMcpTools() cannot run headless (its rows need a booted Craft app for
// ConfigFreshness and isToolEnabled), so the mapping itself is pinned here
// while the flag's own correctness is covered behaviorally above.
it('maps the privileged flag into each listing row', function () {
    $source = (string) file_get_contents((new ReflectionClass(McpTools::class))->getFileName());

    expect($source)->toContain("'privileged' => \$definition->privileged,");
});
