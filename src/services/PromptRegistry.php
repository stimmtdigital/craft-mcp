<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

use Craft;
use stimmt\craft\Mcp\events\RegisterPromptsEvent;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\PromptDefinition;
use stimmt\craft\Mcp\prompts\ContentPrompts;
use stimmt\craft\Mcp\prompts\EntryPrompts;
use stimmt\craft\Mcp\prompts\SchemaPrompts;

/**
 * Registry for MCP prompts with isolation and namespacing support.
 *
 * Collects core prompts and external prompts registered via events,
 * providing safe execution with error isolation.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class PromptRegistry {
    /**
     * Core prompt classes bundled with this plugin.
     * @var string[]
     */
    private const array CORE_PROMPTS = [
        ContentPrompts::class,
        EntryPrompts::class,
        SchemaPrompts::class,
    ];

    /**
     * @var array<string, string[]> Prompt classes grouped by source
     */
    private array $prompts = [];

    /**
     * @var array<string, PromptDefinition> Prompt definitions by name
     */
    private array $definitions = [];

    /**
     * @var string[] Errors encountered during registration
     */
    private array $errors = [];

    /**
     * @var bool Whether prompts have been collected
     */
    private bool $initialized = false;

    /**
     * Get all prompt classes for MCP server registration.
     *
     * @return string[]
     */
    public function getPromptClasses(): array {
        $this->ensureInitialized();

        $classes = [];
        foreach ($this->prompts as $sourcePrompts) {
            $classes = array_merge($classes, $sourcePrompts);
        }

        return $classes;
    }

    /**
     * Get prompts grouped by source for debugging/info.
     *
     * @return array<string, string[]>
     */
    public function getPromptsBySource(): array {
        $this->ensureInitialized();

        return $this->prompts;
    }

    /**
     * Get a specific prompt definition by name.
     */
    public function getDefinition(string $promptName): ?PromptDefinition {
        $this->ensureInitialized();

        return $this->definitions[$promptName] ?? null;
    }

    /**
     * Get all prompt definitions.
     *
     * @return array<string, PromptDefinition>
     */
    public function getDefinitions(): array {
        $this->ensureInitialized();

        return $this->definitions;
    }

    /**
     * Get prompt definitions grouped by source.
     *
     * @return array<string, PromptDefinition[]>
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
     * Get prompt definitions grouped by category.
     *
     * @return array<string, PromptDefinition[]>
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
     * Get prompts that have completion providers.
     *
     * @return PromptDefinition[]
     */
    public function getPromptsWithCompletions(): array {
        $this->ensureInitialized();

        return array_filter(
            $this->definitions,
            fn (PromptDefinition $def): bool => $def->hasCompletions(),
        );
    }

    /**
     * Get any errors encountered during prompt registration.
     *
     * @return string[]
     */
    public function getErrors(): array {
        $this->ensureInitialized();

        return $this->errors;
    }

    /**
     * Get summary of registered prompts for logging.
     *
     * @return array{total: int, by_source: array<string, int>, by_category: array<string, int>, with_completions: int, errors: int}
     */
    public function getSummary(): array {
        $this->ensureInitialized();

        $bySource = [];
        foreach ($this->prompts as $source => $prompts) {
            $bySource[$source] = count($prompts);
        }

        $byCategory = [];
        foreach ($this->definitions as $definition) {
            $byCategory[$definition->category] = ($byCategory[$definition->category] ?? 0) + 1;
        }

        return [
            'total' => count($this->definitions),
            'by_source' => $bySource,
            'by_category' => $byCategory,
            'with_completions' => count($this->getPromptsWithCompletions()),
            'errors' => count($this->errors),
        ];
    }

    /**
     * Reset the registry (useful for testing).
     */
    public function reset(): void {
        $this->initialized = false;
        $this->prompts = [];
        $this->definitions = [];
        $this->errors = [];
    }

    /**
     * Initialize and collect all prompts.
     */
    private function ensureInitialized(): void {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        // Create event and register core prompts first
        $event = new RegisterPromptsEvent();
        $this->collectCorePrompts($event);

        // Fire event for external plugins
        $plugin = Mcp::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(Mcp::EVENT_REGISTER_PROMPTS)) {
            $plugin->trigger(Mcp::EVENT_REGISTER_PROMPTS, $event);
        }

        // Collect everything from the event
        $this->prompts = $event->getPrompts();
        $this->definitions = $event->getDefinitions();
        $this->errors = $event->getErrors();

        // Log warnings for any errors
        foreach ($this->errors as $error) {
            Craft::warning("MCP prompt registration error: {$error}", __METHOD__);
        }

        $summary = $this->getSummary();
        Craft::info(
            sprintf(
                'MCP PromptRegistry initialized: %d prompts (%d with completions) from %s',
                $summary['total'],
                $summary['with_completions'],
                $this->formatSourceSummary($summary['by_source']),
            ),
            __METHOD__,
        );
    }

    /**
     * Register core prompts bundled with this plugin.
     */
    private function collectCorePrompts(RegisterPromptsEvent $event): void {
        // Core prompts will be added in Phase 4
        $event->addCorePrompts(self::CORE_PROMPTS);
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
