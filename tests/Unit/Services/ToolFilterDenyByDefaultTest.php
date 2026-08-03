<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';
if (!class_exists('Craft', false)) {
    require dirname(__DIR__, 3) . '/vendor/craftcms/cms/src/Craft.php';
}

use Mcp\Capability\Registry;
use Mcp\Schema\Tool;
use Psr\Log\NullLogger;
use stimmt\craft\Mcp\services\McpServerFactory;
use stimmt\craft\Mcp\support\EventDispatcher;

/**
 * SDK attribute discovery (McpServerFactory::create()'s setDiscovery()) registers
 * every #[McpTool] method it finds unconditionally, including
 * ConditionalToolProvider classes whose isAvailable() is false. Those never make
 * it into the informational ToolRegistry's definitions, so a tool with no
 * definition must be denied by default in filterTools() or it bypasses
 * disabledTools, scope, and privileged checks entirely (issue #57).
 */
it('unregisters a discovery-registered tool with no definition, keeping a real one', function () {
    $tool = static fn (string $name): Tool => new Tool(
        name: $name,
        title: null,
        inputSchema: ['type' => 'object'],
        description: null,
        annotations: null,
    );

    $registry = new Registry(new EventDispatcher(), new NullLogger());
    $registry->registerTool($tool('get_entry'), static fn (): string => 'ok');
    $registry->registerTool($tool('stray_conditional_tool'), static fn (): string => 'ok');

    $method = new ReflectionMethod(McpServerFactory::class, 'filterTools');
    $method->invoke(new McpServerFactory(), $registry, null);

    $names = array_keys($registry->getTools()->references);

    expect($names)->toContain('get_entry')
        ->and($names)->not->toContain('stray_conditional_tool');
});

it('sums by_source to total in ToolRegistry::getSummary()', function () {
    $summary = stimmt\craft\Mcp\Mcp::getToolRegistry()->getSummary();

    expect(array_sum($summary['by_source']))->toBe($summary['total']);
});
