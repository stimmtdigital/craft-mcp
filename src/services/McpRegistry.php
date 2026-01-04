<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\services;

/**
 * Unified facade for all MCP registries.
 *
 * Provides a single point of access to tool, prompt, and resource registries
 * with shared lifecycle management.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class McpRegistry {
    private static ?ToolRegistry $toolRegistry = null;

    private static ?PromptRegistry $promptRegistry = null;

    private static ?ResourceRegistry $resourceRegistry = null;

    /**
     * Get the tool registry instance.
     */
    public static function tools(): ToolRegistry {
        return self::$toolRegistry ??= new ToolRegistry();
    }

    /**
     * Get the prompt registry instance.
     */
    public static function prompts(): PromptRegistry {
        return self::$promptRegistry ??= new PromptRegistry();
    }

    /**
     * Get the resource registry instance.
     */
    public static function resources(): ResourceRegistry {
        return self::$resourceRegistry ??= new ResourceRegistry();
    }

    /**
     * Reset all registries (useful for testing or hot-reload).
     */
    public static function reset(): void {
        self::$toolRegistry?->reset();
        self::$promptRegistry?->reset();
        self::$resourceRegistry?->reset();

        self::$toolRegistry = null;
        self::$promptRegistry = null;
        self::$resourceRegistry = null;
    }

    /**
     * Get a combined summary of all registries.
     *
     * @return array{
     *     tools: array{total: int, by_source: array<string, int>, by_category: array<string, int>, dangerous: int, errors: int},
     *     prompts: array{total: int, by_source: array<string, int>, by_category: array<string, int>, with_completions: int, errors: int},
     *     resources: array{total: int, static: int, templates: int, by_source: array<string, int>, by_category: array<string, int>, with_completions: int, errors: int}
     * }
     */
    public static function getSummary(): array {
        return [
            'tools' => self::tools()->getSummary(),
            'prompts' => self::prompts()->getSummary(),
            'resources' => self::resources()->getSummary(),
        ];
    }

    /**
     * Get total error count across all registries.
     */
    public static function getTotalErrors(): int {
        return count(self::tools()->getErrors())
            + count(self::prompts()->getErrors())
            + count(self::resources()->getErrors());
    }

    /**
     * Get all errors from all registries.
     *
     * @return array{tools: string[], prompts: string[], resources: string[]}
     */
    public static function getAllErrors(): array {
        return [
            'tools' => self::tools()->getErrors(),
            'prompts' => self::prompts()->getErrors(),
            'resources' => self::resources()->getErrors(),
        ];
    }
}
