<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\support\Build;
use stimmt\craft\Mcp\support\PluginReloader;
use stimmt\craft\Mcp\support\Psr16CacheAdapter;
use stimmt\craft\Mcp\support\Response;
use yii\caching\TagDependency;

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
        title: 'MCP server status',
        description: 'Get information about the Craft MCP plugin including version, status, and configuration',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CORE)]
    public function getMcpInfo(?RequestContext $context = null): array {
        $plugin = Mcp::getInstance();
        $settings = Mcp::settings();
        $registry = Mcp::getToolRegistry();

        $summary = $registry->getSummary();

        return [
            'name' => $plugin !== null ? $plugin->name : 'Craft MCP',
            'handle' => $plugin !== null ? $plugin->handle : 'mcp',
            'version' => $plugin !== null ? $plugin->version : 'unknown',
            // The commit, because the version alone cannot tell two deploys of
            // the same branch apart, and 'dev-main' is what a branch install
            // reports for every commit it will ever have.
            'build' => Build::reference(),
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
        title: 'MCP tool inventory',
        description: 'List all available MCP tools with their names, descriptions, and enabled status',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CORE)]
    public function listMcpTools(?RequestContext $context = null): array {
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
                // Install-introspection reads are hidden from non-admin
                // readonly/content connections, so a listed privileged
                // tool can still be refused; say so rather than letting
                // the listing imply every row is callable.
                'privileged' => $definition->privileged,
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

    /**
     * Reload MCP to detect newly installed plugins.
     *
     * This performs a soft reload that can detect newly installed Craft plugins.
     * For code changes in existing plugins, send SIGHUP to the MCP server process.
     */
    #[McpTool(
        name: 'reload_mcp',
        title: 'Reload the MCP server',
        description: 'Reload MCP to detect newly installed plugins. Note: Code changes require sending SIGHUP to the MCP server process.',
        // Without these the spec's conservative defaults apply, so a client
        // prompts for confirmation before a cache refresh that changes no data.
        annotations: new ToolAnnotations(destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    #[McpToolMeta(category: ToolCategory::CORE)]
    public function reloadMcp(?RequestContext $context = null): array {
        // 1. Reload Composer classmap (detects new plugin classes)
        PluginReloader::reloadComposerClassmap();

        // 2. Refresh Craft's composer plugin info cache (re-reads plugins.php)
        $refreshResult = PluginReloader::refreshComposerPluginInfo();

        // 3. Reset project config to re-read from YAML
        PluginReloader::resetProjectConfig();

        // 4. Reset Plugins service internal caches
        PluginReloader::resetPluginsService();

        // 5. Reload Craft plugins
        Craft::$app->getPlugins()->loadPlugins();

        // 6. Invalidate the cached attribute discovery so it rescans
        TagDependency::invalidate(Craft::$app->getCache(), Psr16CacheAdapter::TAG);

        // 7. Reset tool registry to re-collect tools
        Mcp::resetToolRegistry();

        $summary = Mcp::getToolRegistry()->getSummary();

        // 8. The connected client's own tool list may now be stale;
        // push the real notification instead of leaving it to guess.
        $context?->getClientGateway()->notify(new ToolListChangedNotification());

        return Response::success([
            'message' => 'MCP plugin state reloaded',
            'pluginsDiscovered' => $refreshResult['plugins'],
            'tools' => $summary,
            'hint' => 'For code changes in existing plugins, send SIGHUP to the MCP server process: kill -HUP $(pgrep -f "mcp-server")',
        ]);
    }
}
