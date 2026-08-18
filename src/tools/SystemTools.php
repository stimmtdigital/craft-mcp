<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\console\controllers\HelpController;
use craft\helpers\FileHelper as CraftFileHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\LogEntry;
use stimmt\craft\Mcp\support\LogFormatter;
use stimmt\craft\Mcp\support\LogParser;
use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Presenter;

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
        title: 'Read a config value',
        description: 'Get a Craft CMS configuration value by dot-notation key (e.g., "general.devMode", "db.driver")',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, privileged: true)]
    public function getConfig(
        #[Schema(description: 'Dot-notation key, such as "general.devMode" or "db.driver". A bare category ("general", "db") returns every setting in it; "custom.<file>" reads a config file.')]
        string $key,
        ?RequestContext $context = null,
    ): array {
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
    }

    /**
     * Read recent log entries.
     */
    #[McpTool(
        name: 'read_logs',
        title: 'Read Craft logs',
        description: 'Read recent log entries from Craft CMS logs. Filter by source (web, console, queue, or plugin name), level (error, warning, info), pattern (case-insensitive search), and limit. Use output=text for a human-readable view with indented stack traces.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, privileged: true)]
    public function readLogs(
        int $limit = 50,
        #[Schema(description: 'Exact level to keep, case-insensitive, as the log line spells it (error, warning, info, trace). It is not a minimum, so "warning" excludes errors.')]
        ?string $level = null,
        #[Schema(description: 'Case-insensitive substring the log message must contain.')]
        ?string $pattern = null,
        #[Schema(description: 'Which log to read: web, console, queue, or a plugin name. Omit to read across every log file.')]
        ?string $source = null,
        #[Schema(description: Presenter::OUTPUT_DESCRIPTION)]
        ResponseFormat $output = ResponseFormat::STRUCTURED,
        ?RequestContext $context = null,
    ): array|TextContent {
        $entries = $this->fetchLogEntries($limit, $level, $pattern, $source, $context);

        return match ($output) {
            ResponseFormat::TEXT => (new LogFormatter(Palette::fromSettings()))->format($entries),
            ResponseFormat::STRUCTURED => [
                'count' => count($entries),
                'entries' => array_map(static fn (LogEntry $e): array => $e->toArray(), $entries),
            ],
        };
    }

    /**
     * Fetch and sort log entries.
     *
     * @return LogEntry[]
     */
    private function fetchLogEntries(
        int $limit,
        ?string $level,
        ?string $pattern,
        ?string $source,
        ?RequestContext $context,
    ): array {
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

        return array_slice($entries, 0, $limit);
    }

    /**
     * Get the last error from logs.
     */
    #[McpTool(
        name: 'get_last_error',
        title: 'Most recent error',
        description: 'Get the most recent error from Craft CMS log files',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, privileged: true)]
    public function getLastError(?RequestContext $context = null): array {
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
    }

    /**
     * Clear Craft caches.
     */
    #[McpTool(
        name: 'clear_caches',
        title: 'Clear Craft caches',
        description: 'Clear Craft CMS caches. Specify type: all, data, compiled-templates, temp-files. The response reports which caches were actually cleared.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, dangerous: true)]
    public function clearCaches(
        #[Schema(description: 'Which cache to clear: "all", "data", "compiled-templates", or "temp-files". An unrecognised value clears nothing and reports an empty list.')]
        string $type = 'all',
        ?RequestContext $context = null,
    ): array {
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
    }

    /**
     * List available console commands.
     */
    #[McpTool(
        name: 'list_console_commands',
        title: 'Console commands',
        description: 'List all available Craft CMS console commands (like php craft <command>)',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function listConsoleCommands(?RequestContext $context = null): array {
        $helpController = new HelpController('help', Craft::$app);
        $commands = array_values($helpController->getCommands());

        return [
            'count' => count($commands),
            'commands' => $commands,
        ];
    }

    /**
     * List registered routes.
     */
    #[McpTool(
        name: 'list_routes',
        title: 'Registered routes',
        description: 'List all registered routes in Craft CMS',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM)]
    public function listRoutes(?RequestContext $context = null): array {
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
