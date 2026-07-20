<?php

declare(strict_types=1);

use stimmt\craft\Mcp\tools\McpTools;

// listMcpTools() builds its rows from Mcp::getToolRegistry(), which needs a
// booted Craft app (ConfigFreshness, isToolEnabled). Booting a full Craft app
// in a unit test collides with the plugin's lightweight Yii bootstrap used by
// the rest of the suite (redeclare of class Yii). So this task is guarded
// structurally, the same way PrivilegedToolsTest guards the factory filter.
// The runtime correctness of the two values is covered by their building
// blocks: ToolDefinition->requiredEdition (ToolDefinitionTest) and
// Edition::atLeast()/Mcp::currentEdition() (EditionTest, McpEditionsTest).
it('emits requiredEdition and a locked flag in the tool listing', function () {
    $src = (string) file_get_contents((new ReflectionClass(McpTools::class))->getFileName());

    expect($src)->toContain("'requiredEdition' => \$definition->requiredEdition->value")
        ->and($src)->toContain("'locked' => !Mcp::currentEdition()->atLeast(\$definition->requiredEdition)");
});
