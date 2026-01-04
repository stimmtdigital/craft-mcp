<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\helpers\FileHelper as CraftFileHelper;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\FileHelper;
use Throwable;

/**
 * System-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class SystemTools {
    /**
     * Log line format: 2026-01-03 04:01:45 [web.INFO] [category] message
     */
    private const string LOG_PATTERN = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^.\]]+)\.(\w+)\] \[([^\]]*)\] (.*)$/s';

    /**
     * Core console commands with descriptions.
     */
    private const array CORE_COMMANDS = [
        'cache' => 'Clear and manage caches',
        'clear-caches' => 'Clear various Craft caches',
        'db' => 'Database operations (backup, restore, convert)',
        'gc' => 'Garbage collection',
        'graphql' => 'GraphQL schema operations',
        'index-assets' => 'Re-index assets',
        'install' => 'Install Craft CMS',
        'invalidate-tags' => 'Invalidate cache tags',
        'mailer' => 'Test email configuration',
        'migrate' => 'Run database migrations',
        'off' => 'Turn system off',
        'on' => 'Turn system on',
        'plugin' => 'Manage plugins (install, uninstall, enable, disable)',
        'project-config' => 'Manage project config',
        'queue' => 'Manage queue jobs',
        'resave' => 'Re-save elements',
        'restore' => 'Restore database from backup',
        'setup' => 'Setup wizard',
        'update' => 'Update Craft and plugins',
        'users' => 'Manage users',
    ];

    /**
     * Get a configuration value by key.
     */
    #[McpTool(
        name: 'get_config',
        description: 'Get a Craft CMS configuration value by dot-notation key (e.g., "general.devMode", "db.driver")',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM->value)]
    public function getConfig(string $key): array {
        $parts = explode('.', $key, 2);
        $category = $parts[0];
        $setting = $parts[1] ?? null;

        try {
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
        } catch (Throwable $e) {
            return [
                'success' => false,
                'key' => $key,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Read recent log entries.
     */
    #[McpTool(
        name: 'read_logs',
        description: 'Read recent log entries from Craft CMS logs. Optionally filter by level (error, warning, info) and limit number of entries.',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM->value)]
    public function readLogs(int $limit = 50, ?string $level = null): array {
        $logPath = Craft::$app->getPath()->getLogPath();

        // Find all .log files, prioritizing today's date-based logs
        $today = date('Y-m-d');
        $allLogs = glob($logPath . '/*.log') ?: [];

        // Sort: today's logs first, then by modification time descending
        usort($allLogs, function ($a, $b) use ($today) {
            $aIsToday = str_contains($a, $today);
            $bIsToday = str_contains($b, $today);

            if ($aIsToday && !$bIsToday) {
                return -1;
            }
            if (!$aIsToday && $bIsToday) {
                return 1;
            }

            return filemtime($b) <=> filemtime($a);
        });

        // Limit to most recent logs to avoid processing too many files
        $logFiles = array_slice($allLogs, 0, 5);

        $entries = [];
        foreach ($logFiles as $logFile) {
            $entries = array_merge(
                $entries,
                $this->parseLogFile($logFile, $level, $limit * 2),
            );
        }

        // Sort by timestamp descending and limit
        usort($entries, fn ($a, $b) => strcmp((string) $b['timestamp'], (string) $a['timestamp']));

        return [
            'count' => min(count($entries), $limit),
            'entries' => array_slice($entries, 0, $limit),
        ];
    }

    /**
     * Parse entries from a log file.
     */
    private function parseLogFile(string $logFile, ?string $levelFilter, int $maxLines): array {
        if (!file_exists($logFile)) {
            return [];
        }

        $entries = [];
        $logName = basename($logFile);

        foreach (FileHelper::tail($logFile, $maxLines) as $line) {
            $parsed = $this->parseLogLine($line);
            if ($parsed === null) {
                continue;
            }

            if ($levelFilter !== null && $parsed['level'] !== strtolower($levelFilter)) {
                continue;
            }

            $entries[] = ['file' => $logName, ...$parsed];
        }

        return $entries;
    }

    /**
     * Parse a single log line.
     */
    private function parseLogLine(string $line): ?array {
        if (in_array(trim($line), ['', '0'], true)) {
            return null;
        }

        if (!preg_match(self::LOG_PATTERN, $line, $matches)) {
            return null;
        }

        return [
            'timestamp' => $matches[1],
            'channel' => $matches[2],
            'level' => strtolower($matches[3]),
            'category' => $matches[4],
            'message' => trim($matches[5]),
        ];
    }

    /**
     * Get the last error from logs.
     */
    #[McpTool(
        name: 'get_last_error',
        description: 'Get the most recent error from Craft CMS log files',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM->value)]
    public function getLastError(): array {
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
        description: 'Clear Craft CMS caches. Specify type: all, data, compiled-templates, compiled-classes, asset-indexing-data, temp-files',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM->value, dangerous: true)]
    public function clearCaches(string $type = 'all'): array {
        $cleared = [];

        try {
            if ($type === 'all' || $type === 'data') {
                Craft::$app->getCache()->flush();
                $cleared[] = 'data';
            }

            if ($type === 'all' || $type === 'compiled-templates') {
                $compiledTemplatesPath = Craft::$app->getPath()->getCompiledTemplatesPath(false);
                if ($compiledTemplatesPath && is_dir($compiledTemplatesPath)) {
                    CraftFileHelper::clearDirectory($compiledTemplatesPath);
                    $cleared[] = 'compiled-templates';
                }
            }

            if ($type === 'all' || $type === 'temp-files') {
                $tempPath = Craft::$app->getPath()->getTempPath(false);
                if ($tempPath && is_dir($tempPath)) {
                    CraftFileHelper::clearDirectory($tempPath);
                    $cleared[] = 'temp-files';
                }
            }

            return [
                'success' => true,
                'cleared' => $cleared,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'cleared' => $cleared,
            ];
        }
    }

    /**
     * List available console commands.
     */
    #[McpTool(
        name: 'list_console_commands',
        description: 'List all available Craft CMS console commands (like php craft <command>)',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM->value)]
    public function listConsoleCommands(): array {
        $commands = [];

        foreach (Craft::$app->controllerMap as $id => $controller) {
            $commands[] = [
                'id' => $id,
                'controller' => is_array($controller) ? $controller['class'] : $controller,
            ];
        }

        foreach (self::CORE_COMMANDS as $id => $description) {
            $commands[] = [
                'id' => $id,
                'description' => $description,
            ];
        }

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
        description: 'List all registered routes in Craft CMS',
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM->value)]
    public function listRoutes(): array {
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
        $sections = Craft::$app->getEntries()->getAllSections();
        foreach ($sections as $section) {
            foreach ($section->getSiteSettings() as $siteSettings) {
                if ($siteSettings->hasUrls && $siteSettings->uriFormat) {
                    $routes[] = [
                        'pattern' => $siteSettings->uriFormat,
                        'template' => $siteSettings->template,
                        'type' => 'section',
                        'section' => $section->handle,
                        'siteId' => $siteSettings->siteId,
                    ];
                }
            }
        }

        // Get routes from categories
        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            foreach ($group->getSiteSettings() as $siteSettings) {
                if ($siteSettings->hasUrls && $siteSettings->uriFormat) {
                    $routes[] = [
                        'pattern' => $siteSettings->uriFormat,
                        'template' => $siteSettings->template,
                        'type' => 'category',
                        'group' => $group->handle,
                        'siteId' => $siteSettings->siteId,
                    ];
                }
            }
        }

        return [
            'count' => count($routes),
            'routes' => $routes,
        ];
    }
}
