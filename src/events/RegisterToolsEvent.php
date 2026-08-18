<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\Discoverer;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\contracts\ConditionalProvider;
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
    private const array RESERVED_SOURCES = ['core', 'craft-mcp', 'mcp'];

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
     * The directory should contain classes with #[McpTool] attributes. Every
     * class found is registered exactly as if addTool() had been called for it,
     * so the path is a shorthand for a list of classes rather than a second
     * registration mechanism with its own rules.
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

        $this->registerToolsUnder($path, $subdirs, $source);
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
     * Register every tool class the SDK's own discoverer finds under the path.
     *
     * A reserved source is skipped: core registers its classes by name through
     * addCoreTools(), and its declared path is the whole plugin tree, so
     * scanning it would tokenize every file in src/ to find nothing new.
     *
     * @param string[] $subdirs
     */
    private function registerToolsUnder(string $path, array $subdirs, string $source): void {
        if (in_array($source, self::RESERVED_SOURCES, true)) {
            return;
        }

        $classes = [];
        foreach ((new Discoverer())->discover($path, $subdirs)->getTools() as $reference) {
            $handler = $reference->handler;
            $class = is_array($handler) ? ($handler[0] ?? null) : null;
            if (!is_string($class)) {
                continue;
            }

            $classes[$class] = true;
        }

        foreach (array_keys($classes) as $class) {
            $this->registerToolClass($class, $source);
        }
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

        // Tested against the base interface, not the deprecated subinterface:
        // a class implementing ConditionalProvider directly had its condition
        // silently ignored here, while the prompt and resource events honoured
        // it. Deprecated implementers still match, because the old interface
        // extends this one.
        if (is_subclass_of($class, ConditionalProvider::class) && !$class::isAvailable()) {
            return;
        }

        /** @var class-string $classString */
        $classString = $class;
        $definitions = $this->extractToolDefinitions($classString, $source);
        if ($definitions === []) {
            $this->errors[] = "[{$source}] Class '{$class}' has no public methods with #[McpTool] attribute";

            return;
        }

        // Store class for backwards compatibility
        if (!isset($this->tools[$source])) {
            $this->tools[$source] = [];
        }
        $this->tools[$source][] = $class;

        foreach ($definitions as $definition) {
            $this->definitions[$definition->name] = $definition;
        }
    }

    /**
     * Extract tool definitions from a class.
     *
     * A class-level attribute is honoured first, dispatching through __invoke
     * with the class short name as the default tool name, because that is the
     * invokable shape the SDK's own discoverer supports and ours used to produce
     * no definition for at all.
     *
     * @param class-string $class
     * @return ToolDefinition[]
     */
    private function extractToolDefinitions(string $class, string $source): array {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        $invoke = $this->invokable($reflection);
        $classAttrs = $invoke === null
            ? []
            : $reflection->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($invoke !== null && $classAttrs !== []) {
            return [$this->toolDefinition($classAttrs[0]->newInstance(), $invoke, $source, $reflection->getShortName())];
        }

        $definitions = [];
        foreach ($this->dispatchableMethods($reflection) as $method) {
            $attrs = $method->getAttributes(McpTool::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attrs === []) {
                continue;
            }

            $definitions[] = $this->toolDefinition($attrs[0]->newInstance(), $method, $source, $method->getName());
        }

        return $definitions;
    }

    /**
     * Build one definition from the SDK attribute plus our own policy metadata.
     */
    private function toolDefinition(McpTool $mcpTool, ReflectionMethod $method, string $source, string $defaultName): ToolDefinition {
        $metaAttrs = $method->getAttributes(McpToolMeta::class, ReflectionAttribute::IS_INSTANCEOF);
        $toolMeta = $metaAttrs === [] ? null : $metaAttrs[0]->newInstance();

        return new ToolDefinition(
            name: $mcpTool->name ?? $defaultName,
            description: $mcpTool->description ?? '',
            class: $method->getDeclaringClass()->getName(),
            method: $method->getName(),
            source: $source,
            category: $toolMeta?->category->value ?? ToolCategory::GENERAL->value,
            dangerous: $toolMeta !== null && $toolMeta->dangerous,
            privileged: $toolMeta !== null && $toolMeta->privileged,
            condition: $toolMeta?->condition,
            title: $mcpTool->title,
            annotations: $mcpTool->annotations,
            icons: $mcpTool->icons,
            meta: $mcpTool->meta,
            outputSchema: $mcpTool->outputSchema,
        );
    }

    /**
     * The __invoke a class-level attribute would dispatch through, or null when
     * the class has none the SDK could use.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function invokable(ReflectionClass $reflection): ?ReflectionMethod {
        if (!$reflection->hasMethod('__invoke')) {
            return null;
        }

        $invoke = $reflection->getMethod('__invoke');

        return $invoke->isPublic() && !$invoke->isStatic() ? $invoke : null;
    }

    /**
     * The methods the SDK's discoverer would actually dispatch: declared on this
     * class, and never static, abstract, the constructor, the destructor or
     * __invoke. Accepting a method it skips advertises a tool through
     * list_mcp_tools that tools/call then answers METHOD_NOT_FOUND for.
     *
     * @param ReflectionClass<object> $reflection
     * @return ReflectionMethod[]
     */
    private function dispatchableMethods(ReflectionClass $reflection): array {
        return array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $reflection->getName()
                && !$method->isStatic()
                && !$method->isAbstract()
                && !$method->isConstructor()
                && !$method->isDestructor()
                && $method->getName() !== '__invoke',
        );
    }

    /**
     * Validate a tool class before registration. Whether it carries any usable
     * attribute is answered by extraction itself, so the two never disagree
     * about which methods count.
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

        return null;
    }
}
