<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\tools\AssetTools;
use stimmt\craft\Mcp\tools\BackupTools;
use stimmt\craft\Mcp\tools\CategoryTools;
use stimmt\craft\Mcp\tools\CommerceTools;
use stimmt\craft\Mcp\tools\CraftTools;
use stimmt\craft\Mcp\tools\DatabaseTools;
use stimmt\craft\Mcp\tools\DebugTools;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\GlobalSetTools;
use stimmt\craft\Mcp\tools\GraphqlTools;
use stimmt\craft\Mcp\tools\McpTools;
use stimmt\craft\Mcp\tools\SiteTools;
use stimmt\craft\Mcp\tools\SystemTools;
use stimmt\craft\Mcp\tools\TinkerTools;
use stimmt\craft\Mcp\tools\UserTools;

/**
 * Registry for MCP tools with isolation and namespacing support.
 *
 * Collects core tools and external tools registered via events,
 * providing safe execution with error isolation.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ToolRegistry {
    /**
     * Core tool classes bundled with this plugin.
     */
    private const array CORE_TOOLS = [
        AssetTools::class,
        BackupTools::class,
        CategoryTools::class,
        CraftTools::class,
        DatabaseTools::class,
        DebugTools::class,
        EntryTools::class,
        GlobalSetTools::class,
        GraphqlTools::class,
        McpTools::class,
        SiteTools::class,
        SystemTools::class,
        TinkerTools::class,
        UserTools::class,
    ];

    /**
     * @var array<string, string[]> Tool classes grouped by source
     */
    private array $tools = [];

    /**
     * @var array<string, array{path: string, subdirs: string[]}> Discovery paths grouped by source
     */
    private array $discoveryPaths = [];

    /**
     * @var string[] Errors encountered during registration
     */
    private array $errors = [];

    /**
     * @var bool Whether tools have been collected
     */
    private bool $initialized = false;

    /**
     * Get all tool classes for MCP server registration.
     *
     * @return string[]
     */
    public function getToolClasses(): array {
        $this->ensureInitialized();

        $classes = [];
        foreach ($this->tools as $sourceTools) {
            $classes = array_merge($classes, $sourceTools);
        }

        return $classes;
    }

    /**
     * Get tools grouped by source for debugging/info.
     *
     * @return array<string, string[]>
     */
    public function getToolsBySource(): array {
        $this->ensureInitialized();

        return $this->tools;
    }

    /**
     * Get any errors encountered during tool registration.
     *
     * @return string[]
     */
    public function getErrors(): array {
        $this->ensureInitialized();

        return $this->errors;
    }

    /**
     * Get all discovery paths for MCP SDK.
     *
     * @return array<string, array{path: string, subdirs: string[]}>
     */
    public function getDiscoveryPaths(): array {
        $this->ensureInitialized();

        return $this->discoveryPaths;
    }

    /**
     * Get summary of registered tools for logging.
     *
     * @return array{total: int, by_source: array<string, int>, errors: int}
     */
    public function getSummary(): array {
        $this->ensureInitialized();

        $bySource = [];
        foreach ($this->tools as $source => $tools) {
            $bySource[$source] = count($tools);
        }

        return [
            'total' => count($this->getToolClasses()),
            'by_source' => $bySource,
            'errors' => count($this->errors),
        ];
    }

    /**
     * Initialize and collect all tools.
     */
    private function ensureInitialized(): void {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $this->collectCoreTools();
        $this->collectExternalTools();

        $summary = $this->getSummary();
        Craft::info(
            sprintf(
                'MCP ToolRegistry initialized: %d tools (%s)',
                $summary['total'],
                implode(', ', array_map(
                    fn ($source, $count) => "{$source}: {$count}",
                    array_keys($summary['by_source']),
                    $summary['by_source'],
                )),
            ),
            __METHOD__,
        );
    }

    /**
     * Register core tools bundled with this plugin.
     */
    private function collectCoreTools(): void {
        $tools = self::CORE_TOOLS;

        // Add Commerce tools if Commerce is installed
        if (CommerceTools::isAvailable()) {
            $tools[] = CommerceTools::class;
        }

        $this->tools['mcp'] = $tools;

        // Core plugin discovery path
        $pluginPath = dirname(__DIR__);
        $this->discoveryPaths['mcp'] = [
            'path' => $pluginPath,
            'subdirs' => ['.', 'tools'],
        ];
    }

    /**
     * Collect tools from other plugins via event.
     */
    private function collectExternalTools(): void {
        $event = Mcp::collectExternalTools();

        // Collect tool classes
        foreach ($event->getTools() as $source => $classes) {
            if (!isset($this->tools[$source])) {
                $this->tools[$source] = [];
            }
            $this->tools[$source] = array_merge($this->tools[$source], $classes);
        }

        // Collect discovery paths
        foreach ($event->getDiscoveryPaths() as $source => $pathInfo) {
            $this->discoveryPaths[$source] = $pathInfo;
        }

        $this->errors = array_merge($this->errors, $event->getErrors());
    }
}
