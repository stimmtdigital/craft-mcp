<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\Mcp;

/**
 * Self-awareness tools for the MCP plugin.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class McpTools {
    /**
     * Get information about the MCP plugin itself.
     */
    #[McpTool(
        name: 'get_mcp_info',
        description: 'Get information about the Craft MCP plugin including version, status, and configuration',
    )]
    #[McpToolMeta(category: ToolCategory::CORE->value)]
    public function getMcpInfo(): array {
        $plugin = Mcp::getInstance();
        $settings = Mcp::settings();
        $registry = Mcp::getToolRegistry();

        $summary = $registry->getSummary();

        return [
            'name' => $plugin !== null ? $plugin->name : 'Craft MCP',
            'handle' => $plugin !== null ? $plugin->handle : 'mcp',
            'version' => $plugin !== null ? $plugin->version : 'unknown',
            'schemaVersion' => $plugin !== null ? $plugin->schemaVersion : 'unknown',
            'status' => [
                'enabled' => $settings->enabled,
                'dangerousToolsEnabled' => $settings->enableDangerousTools,
                'environment' => Craft::$app->env ?? getenv('CRAFT_ENVIRONMENT') ?: 'production',
            ],
            'tools' => [
                'total' => $summary['total'],
                'bySource' => $summary['by_source'],
                'byCategory' => $summary['by_category'],
                'dangerous' => $summary['dangerous'],
                'errors' => $summary['errors'],
            ],
            'configuration' => [
                'disabledTools' => $settings->disabledTools,
            ],
        ];
    }

    /**
     * List all available MCP tools with their descriptions.
     */
    #[McpTool(
        name: 'list_mcp_tools',
        description: 'List all available MCP tools with their names, descriptions, and enabled status',
    )]
    #[McpToolMeta(category: ToolCategory::CORE->value)]
    public function listMcpTools(): array {
        $registry = Mcp::getToolRegistry();
        $definitions = $registry->getDefinitions();

        $tools = [];
        foreach ($definitions as $definition) {
            $tools[] = [
                'name' => $definition->name,
                'description' => $definition->description,
                'source' => $definition->source,
                'category' => $definition->category,
                'dangerous' => $definition->dangerous,
                'enabled' => Mcp::isToolEnabled($definition->name),
            ];
        }

        // Sort by source, then category, then name
        usort($tools, function (array $a, array $b): int {
            $sourceCompare = strcmp($a['source'], $b['source']);
            if ($sourceCompare !== 0) {
                return $sourceCompare;
            }

            $categoryCompare = strcmp($a['category'], $b['category']);
            if ($categoryCompare !== 0) {
                return $categoryCompare;
            }

            return strcmp($a['name'], $b['name']);
        });

        // Group counts
        $bySource = [];
        $byCategory = [];
        foreach ($tools as $tool) {
            $bySource[$tool['source']] = ($bySource[$tool['source']] ?? 0) + 1;
            $byCategory[$tool['category']] = ($byCategory[$tool['category']] ?? 0) + 1;
        }

        return [
            'count' => count($tools),
            'bySource' => $bySource,
            'byCategory' => $byCategory,
            'tools' => $tools,
        ];
    }
}
