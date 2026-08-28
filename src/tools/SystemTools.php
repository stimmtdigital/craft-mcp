<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\console\controllers\HelpController;
use craft\helpers\FileHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\Formatter;
use stimmt\craft\Mcp\logging\Parser;
use stimmt\craft\Mcp\logging\Search;
use stimmt\craft\Mcp\pipeline\Presenter;
use stimmt\craft\Mcp\support\Secrets;
use stimmt\craft\Mcp\support\Window;
use stimmt\craft\Mcp\text\Palette;

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
        description: 'Get a Craft CMS configuration value by dot-notation key (e.g., "general.devMode", "db.driver"). Credentials such as the security key and the database password come back redacted, named but not revealed.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::SYSTEM, privileged: true)]
    public function getConfig(
        #[Schema(description: 'Dot-notation key, such as "general.devMode" or "db.driver". A bare category ("general", "db") returns every setting in it; "custom.<file>" reads a config file. Credential settings report a redaction placeholder in place of their value.')]
        string $key,
        ?RequestContext $context = null,
    ): array {
        $parts = explode('.', $key, 2);
        $category = $parts[0];
        $setting = $parts[1] ?? null;

        $config = Craft::$app->getConfig();

        $value = match ($category) {
            'general' => $this->setting($config->getGeneral(), $setting, $key),
            // The credential settings are listed rather than left out: leaving
            // them out is what made the category disagree with the keyed read,
            // and told a caller the install has no database password. Which of
            // them are withheld is not decided here.
            'db' => $setting !== null
                ? $this->setting($config->getDb(), $setting, $key)
                : [
                    'driver' => $config->getDb()->driver,
                    'server' => $config->getDb()->server,
                    'port' => $config->getDb()->port,
                    'database' => $config->getDb()->database,
                    'tablePrefix' => $config->getDb()->tablePrefix,
                    'dsn' => $config->getDb()->dsn,
                    'url' => $config->getDb()->url,
                    'user' => $config->getDb()->user,
                    'password' => $config->getDb()->password,
                ],
            'custom' => $config->getConfigFromFile($setting ?? 'custom'),
            default => throw new ToolCallException($this->unknownCategory($category)),
        };

        return [
            'key' => $key,
            'value' => Secrets::conceal($key, $value),
        ];
    }

    /**
     * One setting off a config object, or the whole object as an array.
     *
     * A name that does not exist is refused rather than answered with null.
     * Craft has settings that are legitimately null, so null for a misspelled
     * name told a caller their typo was an unset setting, and nothing on the
     * outside could tell those two apart.
     */
    private function setting(object $config, ?string $setting, string $key): mixed {
        if ($setting === null) {
            return (array) $config;
        }

        if (!property_exists($config, $setting)) {
            $category = explode('.', $key, 2)[0];

            throw new ToolCallException(
                "Unknown config setting '{$key}'. Read the whole category to see what it holds: "
                . "call get_config with key '{$category}'.",
            );
        }

        return $config->$setting;
    }

    /**
     * The suggestion is the useful half. A bare setting name is the likely
     * mistake, and `devMode` used to answer with this very sentence as the
     * VALUE of a successful call, which an agent has no way to read as failure.
     */
    private function unknownCategory(string $category): string {
        $message = "Unknown config category '{$category}'. Use 'general', 'db', or 'custom.<file>'.";

        return property_exists(Craft::$app->getConfig()->getGeneral(), $category)
            ? $message . " Did you mean 'general.{$category}'?"
            : $message;
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
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
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
        Window::assert($limit);

        $entries = $this->fetchLogEntries($limit, $level, $pattern, $source, $context);

        return match ($output) {
            ResponseFormat::TEXT => (new Formatter(Palette::fromSettings()))->format($entries),
            ResponseFormat::STRUCTURED => [
                'count' => count($entries),
                'entries' => array_map(static fn (Entry $e): array => $e->toArray(), $entries),
            ],
        };
    }

    /**
     * Fetch the newest log entries matching the filter.
     *
     * WHY this is one call: limit bounds the entries returned, not the lines
     * searched. The search walks each log backwards until it has enough
     * matches, so asking for two errors no longer means looking at the last
     * four lines and reporting that there are none.
     *
     * @return Entry[]
     */
    private function fetchLogEntries(
        int $limit,
        ?string $level,
        ?string $pattern,
        ?string $source,
        ?RequestContext $context,
    ): array {
        $parser = new Parser(Craft::$app->getPath()->getLogPath());
        $gateway = $context?->getClientGateway();

        return $parser->newest(
            new Search($limit, $level, $pattern, $source),
            static function (string $file, int $position, int $total) use ($gateway): void {
                $gateway?->progress($position, $total, 'Scanning ' . basename($file));
            },
        );
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
        $result = $this->readLogs(1, 'error', context: $context);

        if (empty($result['entries'])) {
            // Say how deep the search went. This is the tool an agent reaches
            // for during an incident, and "no errors" without a depth reads as
            // a statement about the install rather than about the search.
            return [
                'found' => false,
                'message' => 'No errors found. Each log file is searched back at most ' . Parser::scanDepth() . '.',
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

        FileHelper::clearDirectory($path);
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
