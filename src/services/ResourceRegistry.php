<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use stimmt\craft\Mcp\events\RegisterResourcesEvent;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\ResourceDefinition;
use stimmt\craft\Mcp\resources\ConfigResources;
use stimmt\craft\Mcp\resources\EntryResources;
use stimmt\craft\Mcp\resources\SchemaResources;

/**
 * Registry for MCP resources with isolation and namespacing support.
 *
 * Collects core resources and external resources registered via events,
 * providing safe execution with error isolation.
 *
 * Handles both static resources (McpResource) and resource templates (McpResourceTemplate).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ResourceRegistry {
    /**
     * Core resource classes bundled with this plugin.
     * @var string[]
     */
    private const array CORE_RESOURCES = [
        ConfigResources::class,
        EntryResources::class,
        SchemaResources::class,
    ];

    /**
     * @var array<string, string[]> Resource classes grouped by source
     */
    private array $resources = [];

    /**
     * @var array<string, ResourceDefinition> Resource definitions by URI/name
     */
    private array $definitions = [];

    /**
     * @var string[] Errors encountered during registration
     */
    private array $errors = [];

    /**
     * @var bool Whether resources have been collected
     */
    private bool $initialized = false;

    /**
     * Get all resource classes for MCP server registration.
     *
     * @return string[]
     */
    public function getResourceClasses(): array {
        $this->ensureInitialized();

        $classes = [];
        foreach ($this->resources as $sourceResources) {
            $classes = array_merge($classes, $sourceResources);
        }

        return $classes;
    }

    /**
     * Get resources grouped by source for debugging/info.
     *
     * @return array<string, string[]>
     */
    public function getResourcesBySource(): array {
        $this->ensureInitialized();

        return $this->resources;
    }

    /**
     * Get a specific resource definition by URI (static) or name (template).
     */
    public function getDefinition(string $key): ?ResourceDefinition {
        $this->ensureInitialized();

        return $this->definitions[$key] ?? null;
    }

    /**
     * Get all resource definitions.
     *
     * @return array<string, ResourceDefinition>
     */
    public function getDefinitions(): array {
        $this->ensureInitialized();

        return $this->definitions;
    }

    /**
     * Get static resource definitions only.
     *
     * @return ResourceDefinition[]
     */
    public function getStaticDefinitions(): array {
        $this->ensureInitialized();

        return array_filter(
            $this->definitions,
            fn (ResourceDefinition $def): bool => !$def->isTemplate,
        );
    }

    /**
     * Get resource template definitions only.
     *
     * @return ResourceDefinition[]
     */
    public function getTemplateDefinitions(): array {
        $this->ensureInitialized();

        return array_filter(
            $this->definitions,
            fn (ResourceDefinition $def): bool => $def->isTemplate,
        );
    }

    /**
     * Get resource definitions grouped by source.
     *
     * @return array<string, ResourceDefinition[]>
     */
    public function getDefinitionsBySource(): array {
        $this->ensureInitialized();

        $bySource = [];
        foreach ($this->definitions as $definition) {
            $bySource[$definition->source][] = $definition;
        }

        return $bySource;
    }

    /**
     * Get resource definitions grouped by category.
     *
     * @return array<string, ResourceDefinition[]>
     */
    public function getDefinitionsByCategory(): array {
        $this->ensureInitialized();

        $byCategory = [];
        foreach ($this->definitions as $definition) {
            $byCategory[$definition->category][] = $definition;
        }

        return $byCategory;
    }

    /**
     * Get resources that have completion providers (templates only).
     *
     * @return ResourceDefinition[]
     */
    public function getResourcesWithCompletions(): array {
        $this->ensureInitialized();

        return array_filter(
            $this->definitions,
            fn (ResourceDefinition $def): bool => $def->hasCompletions(),
        );
    }

    /**
     * Get any errors encountered during resource registration.
     *
     * @return string[]
     */
    public function getErrors(): array {
        $this->ensureInitialized();

        return $this->errors;
    }

    /**
     * Get summary of registered resources for logging.
     *
     * @return array{total: int, static: int, templates: int, by_source: array<string, int>, by_category: array<string, int>, with_completions: int, errors: int}
     */
    public function getSummary(): array {
        $this->ensureInitialized();

        $bySource = [];
        foreach ($this->resources as $source => $resources) {
            $bySource[$source] = count($resources);
        }

        $byCategory = [];
        foreach ($this->definitions as $definition) {
            $byCategory[$definition->category] = ($byCategory[$definition->category] ?? 0) + 1;
        }

        return [
            'total' => count($this->definitions),
            'static' => count($this->getStaticDefinitions()),
            'templates' => count($this->getTemplateDefinitions()),
            'by_source' => $bySource,
            'by_category' => $byCategory,
            'with_completions' => count($this->getResourcesWithCompletions()),
            'errors' => count($this->errors),
        ];
    }

    /**
     * Reset the registry (useful for testing).
     */
    public function reset(): void {
        $this->initialized = false;
        $this->resources = [];
        $this->definitions = [];
        $this->errors = [];
    }

    /**
     * Initialize and collect all resources.
     */
    private function ensureInitialized(): void {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Create event and register core resources first
        $event = new RegisterResourcesEvent();
        $this->collectCoreResources($event);

        // Fire event for external plugins
        $plugin = Mcp::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(Mcp::EVENT_REGISTER_RESOURCES)) {
            $plugin->trigger(Mcp::EVENT_REGISTER_RESOURCES, $event);
        }

        // Collect everything from the event
        $this->resources = $event->getResources();
        $this->definitions = $event->getDefinitions();
        $this->errors = $event->getErrors();

        // Log warnings for any errors
        foreach ($this->errors as $error) {
            Craft::warning("MCP resource registration error: {$error}", __METHOD__);
        }

        $summary = $this->getSummary();
        Craft::info(
            sprintf(
                'MCP ResourceRegistry initialized: %d resources (%d static, %d templates, %d with completions) from %s',
                $summary['total'],
                $summary['static'],
                $summary['templates'],
                $summary['with_completions'],
                $this->formatSourceSummary($summary['by_source']),
            ),
            __METHOD__,
        );
    }

    /**
     * Register core resources bundled with this plugin.
     */
    private function collectCoreResources(RegisterResourcesEvent $event): void {
        // Core resources will be added in Phase 4
        $event->addCoreResources(self::CORE_RESOURCES);
    }

    /**
     * Format source summary for logging.
     *
     * @param array<string, int> $bySource
     */
    private function formatSourceSummary(array $bySource): string {
        if ($bySource === []) {
            return 'no sources';
        }

        return implode(', ', array_map(
            fn (string $source, int $count): string => "{$source}: {$count}",
            array_keys($bySource),
            $bySource,
        ));
    }
}
