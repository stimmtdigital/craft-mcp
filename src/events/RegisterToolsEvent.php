<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\contracts\ConditionalToolProvider;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\models\ToolDefinition;
use yii\base\Event;

/**
 * Event fired to allow other plugins to register MCP tools.
 *
 * Example usage in another plugin:
 *
 * ```php
 * use stimmt\craft\Mcp\Mcp;
 * use stimmt\craft\Mcp\events\RegisterToolsEvent;
 * use stimmt\craft\Mcp\attributes\McpToolMeta;
 * use yii\base\Event;
 *
 * Event::on(
 *     Mcp::class,
 *     Mcp::EVENT_REGISTER_TOOLS,
 *     function(RegisterToolsEvent $event) {
 *         $event->addTool(MyPluginTools::class, 'my-plugin');
 *     }
 * );
 * ```
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class RegisterToolsEvent extends Event {
    /**
     * Reserved sources that external plugins cannot use.
     */
    private const RESERVED_SOURCES = ['core', 'craft-mcp', 'mcp'];

    /**
     * Registered tool classes grouped by source.
     * @var array<string, string[]> ['source' => ['ToolClass1', 'ToolClass2']]
     */
    private array $tools = [];

    /**
     * Registered tool definitions by name.
     * @var array<string, ToolDefinition>
     */
    private array $definitions = [];

    /**
     * Registered tool directories for MCP SDK discovery.
     * @var array<string, array{path: string, subdirs: string[]}> ['source' => ['path' => '...', 'subdirs' => [...]]]
     */
    private array $discoveryPaths = [];

    /**
     * Validation errors encountered during registration.
     * @var string[]
     */
    private array $errors = [];

    /**
     * Register a tool class with validation.
     *
     * @param string $class Fully qualified class name
     * @param string $source Source identifier (plugin handle) for namespacing
     * @throws InvalidArgumentException If class is invalid
     */
    public function addTool(string $class, string $source = 'plugin'): void {
        // Protect reserved sources
        if (in_array($source, self::RESERVED_SOURCES, true)) {
            $this->errors[] = "[{$source}] Source '{$source}' is reserved for core tools. Use your plugin handle.";

            return;
        }

        $this->registerToolClass($class, $source);
    }

    /**
     * Register core tool classes (internal use only).
     *
     * This method bypasses source validation and uses 'core' as the source.
     *
     * @param string[] $classes Array of fully qualified class names
     * @internal
     */
    public function addCoreTools(array $classes): void {
        foreach ($classes as $class) {
            $this->registerToolClass($class, 'core');
        }
    }

    /**
     * Get all registered tool classes.
     *
     * @return array<string, string[]>
     */
    public function getTools(): array {
        return $this->tools;
    }

    /**
     * Get flat list of all tool classes.
     *
     * @return string[]
     */
    public function getAllToolClasses(): array {
        $classes = [];
        foreach ($this->tools as $sourceTools) {
            $classes = array_merge($classes, $sourceTools);
        }

        return $classes;
    }

    /**
     * Get all tool definitions.
     *
     * @return array<string, ToolDefinition>
     */
    public function getDefinitions(): array {
        return $this->definitions;
    }

    /**
     * Get tool definitions grouped by source.
     *
     * @return array<string, ToolDefinition[]>
     */
    public function getDefinitionsBySource(): array {
        $bySource = [];
        foreach ($this->definitions as $definition) {
            $bySource[$definition->source][] = $definition;
        }

        return $bySource;
    }

    /**
     * Get tool definitions grouped by category.
     *
     * @return array<string, ToolDefinition[]>
     */
    public function getDefinitionsByCategory(): array {
        $byCategory = [];
        foreach ($this->definitions as $definition) {
            $byCategory[$definition->category][] = $definition;
        }

        return $byCategory;
    }

    /**
     * Get validation errors that occurred during registration.
     *
     * @return string[]
     */
    public function getErrors(): array {
        return $this->errors;
    }

    /**
     * Register a directory for MCP tool discovery.
     *
     * The directory should contain classes with #[McpTool] attributes.
     *
     * @param string $path Absolute path to the directory containing tool classes
     * @param string[] $subdirs Subdirectories to scan (e.g., ['.', 'tools'])
     * @param string $source Source identifier (plugin handle)
     */
    public function addDiscoveryPath(string $path, array $subdirs, string $source): void {
        if (!is_dir($path)) {
            $this->errors[] = "[{$source}] Discovery path does not exist: {$path}";

            return;
        }

        $this->discoveryPaths[$source] = [
            'path' => $path,
            'subdirs' => $subdirs,
        ];
    }

    /**
     * Get all registered discovery paths.
     *
     * @return array<string, array{path: string, subdirs: string[]}>
     */
    public function getDiscoveryPaths(): array {
        return $this->discoveryPaths;
    }

    /**
     * Register a tool class and extract its definitions.
     */
    private function registerToolClass(string $class, string $source): void {
        $error = $this->validateToolClass($class);
        if ($error !== null) {
            $this->errors[] = "[{$source}] {$error}";

            return;
        }

        // Check class-level condition
        if (is_subclass_of($class, ConditionalToolProvider::class)) {
            if (!$class::isAvailable()) {
                return;
            }
        }

        // Store class for backwards compatibility
        if (!isset($this->tools[$source])) {
            $this->tools[$source] = [];
        }
        $this->tools[$source][] = $class;

        // Extract and store definitions
        foreach ($this->extractToolDefinitions($class, $source) as $definition) {
            $this->definitions[$definition->name] = $definition;
        }
    }

    /**
     * Extract tool definitions from a class.
     *
     * @return ToolDefinition[]
     */
    private function extractToolDefinitions(string $class, string $source): array {
        $definitions = [];

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $mcpToolAttrs = $method->getAttributes(McpTool::class);
            if (empty($mcpToolAttrs)) {
                continue;
            }

            $mcpTool = $mcpToolAttrs[0]->newInstance();

            // Get optional McpToolMeta attribute
            $metaAttrs = $method->getAttributes(McpToolMeta::class);
            $meta = !empty($metaAttrs) ? $metaAttrs[0]->newInstance() : null;

            $definitions[] = new ToolDefinition(
                name: $mcpTool->name,
                description: $mcpTool->description,
                class: $class,
                method: $method->getName(),
                source: $source,
                category: $meta !== null ? $meta->category : ToolCategory::GENERAL->value,
                dangerous: $meta !== null ? $meta->dangerous : false,
                condition: $meta?->condition,
            );
        }

        return $definitions;
    }

    /**
     * Validate a tool class before registration.
     *
     * @return string|null Error message or null if valid
     */
    private function validateToolClass(string $class): ?string {
        if (!class_exists($class)) {
            return "Class '{$class}' does not exist";
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            return "Cannot reflect class '{$class}': {$e->getMessage()}";
        }

        if ($reflection->isAbstract()) {
            return "Class '{$class}' is abstract and cannot be used as a tool";
        }

        if (!$reflection->isInstantiable()) {
            return "Class '{$class}' is not instantiable";
        }

        // Check for at least one method with McpTool attribute
        $hasToolMethod = false;
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(McpTool::class);
            if (!empty($attributes)) {
                $hasToolMethod = true;
                break;
            }
        }

        if (!$hasToolMethod) {
            return "Class '{$class}' has no public methods with #[McpTool] attribute";
        }

        return null;
    }
}
