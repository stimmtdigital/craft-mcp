<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use yii\base\Event;

/**
 * Event fired to allow other plugins to register MCP tools.
 *
 * Example usage in another plugin:
 *
 * ```php
 * use stimmt\craft\Mcp\Mcp;
 * use stimmt\craft\Mcp\events\RegisterToolsEvent;
 * use yii\base\Event;
 *
 * Event::on(
 *     Mcp::class,
 *     Mcp::EVENT_REGISTER_TOOLS,
 *     function(RegisterToolsEvent $event) {
 *         $event->addTool(MyPluginTools::class, 'myplugin');
 *     }
 * );
 * ```
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class RegisterToolsEvent extends Event {
    /**
     * Registered tool classes grouped by source.
     * @var array<string, string[]> ['source' => ['ToolClass1', 'ToolClass2']]
     */
    private array $tools = [];

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
    public function addTool(string $class, string $source = 'external'): void {
        $error = $this->validateToolClass($class);
        if ($error !== null) {
            $this->errors[] = "[{$source}] {$error}";

            return;
        }

        if (!isset($this->tools[$source])) {
            $this->tools[$source] = [];
        }

        $this->tools[$source][] = $class;
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
