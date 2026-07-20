<?php

declare(strict_types=1);

// Mcp::currentEdition() touches Yii::$app; load the plugin's Yii bootstrap,
// matching the convention used by the other unit tests (for example
// tests/Unit/McpEditionsTest.php). Booting a full Craft app here would clash
// with that bootstrap (redeclare of class Yii), so listMcpTools() itself is not
// invoked; its edition columns are produced by McpTools::editionFields(), which
// is what this test exercises directly.
require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\models\ToolDefinition;
use stimmt\craft\Mcp\tools\McpTools;

it('maps requiredEdition and a locked flag onto a tool row', function () {
    $pro = ToolDefinition::fromArray(['name' => 'create_entry', 'requiredEdition' => Edition::Pro]);
    $free = ToolDefinition::fromArray(['name' => 'get_entry']);

    // No plugin instance is loaded in a unit test, so the active edition is
    // Standard: a Pro tool is locked, a Standard tool is not.
    expect(McpTools::editionFields($pro))->toBe(['requiredEdition' => 'pro', 'locked' => true])
        ->and(McpTools::editionFields($free))->toBe(['requiredEdition' => 'standard', 'locked' => false]);
});
