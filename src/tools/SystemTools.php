<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\console\controllers\HelpController;
use craft\helpers\FileHelper as CraftFileHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\LogEntry;
use stimmt\craft\Mcp\support\LogParser;
use stimmt\craft\Mcp\support\SafeExecution;

/**
 * System-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class SystemTools {
    /**
     * Get a configuration value by key.
     */
    #[McpTool(
        name: 'get_config',
        description: 'Get a Craft CMS configuration value by dot-notation key (e.g., "general.devMode", "db.driver")',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function getConfig(string $key, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($key): array {
            $parts = explode('.', $key, 2);
            $category = $parts[0];
            $setting = $parts[1] ?? null;

            $config = Craft::$app->getConfig();

            $value = match ($category) {
                'general' => $setting
                    ? $config->getGeneral()->$setting ?? null
                    : (array) $config->getGeneral(),
                'db' => $setting
                    ? $config->getDb()->$setting ?? null
                    : [
                        'driver' => $config->getDb()->driver,
                        'server' => $config->getDb()->server,
                        'port' => $config->getDb()->port,
                        'database' => $config->getDb()->database,
                        'tablePrefix' => $config->getDb()->tablePrefix,
                    ],
                'custom' => $config->getConfigFromFile($setting ?? 'custom'),
                default => "Unknown config category: {$category}",
            };

            return [
                'key' => $key,
                'value' => $value,
            ];
        });
    }

    /**
     * Read recent log entries.
     */
    #[McpTool(
        name: 'read_logs',
        description: 'Read recent log entries from Craft CMS logs. Filter by source (web, console, queue, or plugin name), level (error, warning, info), pattern (case-insensitive search), and limit.',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function readLogs(int $limit = 50, ?string $level = null, ?string $pattern = null, ?string $source = null, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($limit, $level, $pattern, $source, $context): array {
            $parser = new LogParser(Craft::$app->getPath()->getLogPath());

            $files = $parser->discoverLogFiles($source);
            $entries = [];
            $gateway = $context?->getClientGateway();
            $totalFiles = count($files);

            foreach ($files as $index => $file) {
                $gateway?->progress($index + 1, $totalFiles, 'Parsing ' . basename($file));

                $entries = array_merge(
                    $entries,
                    $parser->parseFile($file, $level, $pattern, $limit * 2),
                );
            }

            usort($entries, static fn (LogEntry $a, LogEntry $b): int => $b->timestamp <=> $a->timestamp);

            $limited = array_slice($entries, 0, $limit);

            return [
                'count' => count($limited),
                'entries' => array_map(static fn (LogEntry $e): array => $e->toArray(), $limited),
            ];
        });
    }

    /**
     * Get the last error from logs.
     */
    #[McpTool(
        name: 'get_last_error',
        description: 'Get the most recent error from Craft CMS log files',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function getLastError(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $result = $this->readLogs(1, 'error');

            if (empty($result['entries'])) {
                return [
                    'found' => false,
                    'message' => 'No errors found in recent logs',
                ];
            }

            return [
                'found' => true,
                'error' => $result['entries'][0],
            ];
        });
    }

    /**
     * Clear Craft caches.
     */
    #[McpTool(
        name: 'clear_caches',
        description: 'Clear Craft CMS caches. Specify type: all, data, compiled-templates, compiled-classes, asset-indexing-data, temp-files',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, dangerous: true)]
    public function clearCaches(string $type = 'all', ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($type): array {
            $cleared = [];

            if ($type === 'all' || $type === 'data') {
                Craft::$app->getCache()->flush();
                $cleared[] = 'data';
            }

            if ($type === 'all' || $type === 'compiled-templates') {
                $this->clearDirectoryIfExists(
                    Craft::$app->getPath()->getCompiledTemplatesPath(false),
                    'compiled-templates',
                    $cleared,
                );
            }

            if ($type === 'all' || $type === 'temp-files') {
                $this->clearDirectoryIfExists(
                    Craft::$app->getPath()->getTempPath(false),
                    'temp-files',
                    $cleared,
                );
            }

            return [
                'success' => true,
                'cleared' => $cleared,
            ];
        });
    }

    /**
     * List available console commands.
     */
    #[McpTool(
        name: 'list_console_commands',
        description: 'List all available Craft CMS console commands (like php craft <command>)',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function listConsoleCommands(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $helpController = new HelpController('help', Craft::$app);
            $commands = array_values($helpController->getCommands());

            return [
                'count' => count($commands),
                'commands' => $commands,
            ];
        });
    }

    /**
     * List registered routes.
     */
    #[McpTool(
        name: 'list_routes',
        description: 'List all registered routes in Craft CMS',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function listRoutes(?RequestContext $context = null): array {
        return SafeExecution::run(function (): array {
            $routes = [];

            // Get custom routes from config
            $configRoutes = Craft::$app->getConfig()->getConfigFromFile('routes') ?: [];
            foreach ($configRoutes as $pattern => $template) {
                $routes[] = [
                    'pattern' => $pattern,
                    'template' => is_array($template) ? ($template['template'] ?? json_encode($template)) : $template,
                    'type' => 'config',
                ];
            }

            // Get routes from sections (entry URLs)
            $sectionRoutes = $this->extractSectionRoutes(Craft::$app->getEntries()->getAllSections());
            $routes = array_merge($routes, $sectionRoutes);

            // Get routes from categories
            $categoryRoutes = $this->extractCategoryRoutes(Craft::$app->getCategories()->getAllGroups());
            $routes = array_merge($routes, $categoryRoutes);

            return [
                'count' => count($routes),
                'routes' => $routes,
            ];
        });
    }

    /**
     * Clear a directory if it exists.
     *
     * @param string[] $cleared Modified in place
     */
    private function clearDirectoryIfExists(?string $path, string $name, array &$cleared): void {
        if ($path === null || !is_dir($path)) {
            return;
        }

        CraftFileHelper::clearDirectory($path);
        $cleared[] = $name;
    }

    /**
     * Extract routes from sections.
     *
     * @param Section[] $sections
     * @return array<array<string, mixed>>
     */
    private function extractSectionRoutes(array $sections): array {
        return array_merge(...array_map(
            fn ($section) => $this->extractSiteSettingRoutes(
                $section->getSiteSettings(),
                'section',
                ['section' => $section->handle],
            ),
            $sections,
        ));
    }

    /**
     * Extract routes from category groups.
     *
     * @param CategoryGroup[] $groups
     * @return array<array<string, mixed>>
     */
    private function extractCategoryRoutes(array $groups): array {
        return array_merge(...array_map(
            fn ($group) => $this->extractSiteSettingRoutes(
                $group->getSiteSettings(),
                'category',
                ['group' => $group->handle],
            ),
            $groups,
        ));
    }

    /**
     * Extract routes from site settings.
     *
     * @param array<string, mixed> $extra
     * @return array<array<string, mixed>>
     */
    private function extractSiteSettingRoutes(array $siteSettings, string $type, array $extra): array {
        return array_filter(
            array_map(
                fn ($settings) => $settings->hasUrls && $settings->uriFormat
                    ? [
                        'pattern' => $settings->uriFormat,
                        'template' => $settings->template,
                        'type' => $type,
                        ...$extra,
                        'siteId' => $settings->siteId,
                    ]
                    : null,
                $siteSettings,
            ),
        );
    }
}
