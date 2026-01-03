<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use ReflectionClass;
use ReflectionMethod;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\services\ToolRegistry;
use Throwable;

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
    public function getMcpInfo(): array {
        $plugin = Mcp::getInstance();
        $settings = Mcp::settings();
        $registry = new ToolRegistry();

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
    public function listMcpTools(): array {
        $registry = new ToolRegistry();
        $toolClasses = $registry->getToolClasses();

        $tools = [];
        $dangerousTools = Mcp::DANGEROUS_TOOLS;

        foreach ($toolClasses as $toolClass) {
            if (!class_exists($toolClass)) {
                continue;
            }

            $classTools = $this->extractToolsFromClass($toolClass, $dangerousTools);
            $tools = array_merge($tools, $classTools);
        }

        // Sort by name
        usort($tools, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return [
            'count' => count($tools),
            'tools' => $tools,
        ];
    }

    /**
     * Extract tool information from a class using reflection.
     *
     * @param class-string $toolClass
     * @param string[] $dangerousTools
     * @return array<array{name: string, description: string, class: string, dangerous: bool, enabled: bool}>
     */
    private function extractToolsFromClass(string $toolClass, array $dangerousTools): array {
        $tools = [];

        try {
            $reflection = new ReflectionClass($toolClass);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $attributes = $method->getAttributes(McpTool::class);

                if (empty($attributes)) {
                    continue;
                }

                $attribute = $attributes[0]->newInstance();

                $name = $attribute->name ?? $method->getName();
                $isDangerous = in_array($name, $dangerousTools, true);

                $tools[] = [
                    'name' => $name,
                    'description' => $attribute->description ?? '',
                    'class' => $toolClass,
                    'method' => $method->getName(),
                    'dangerous' => $isDangerous,
                    'enabled' => Mcp::isToolEnabled($name),
                ];
            }
        } catch (Throwable) {
            // Skip classes that can't be reflected
        }

        return $tools;
    }
}
