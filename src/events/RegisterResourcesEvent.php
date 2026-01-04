<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use stimmt\craft\Mcp\attributes\McpResourceMeta;
use stimmt\craft\Mcp\contracts\ConditionalProvider;
use stimmt\craft\Mcp\enums\ResourceCategory;
use stimmt\craft\Mcp\models\ResourceDefinition;
use yii\base\Event;

/**
 * Event fired to allow other plugins to register MCP resources.
 *
 * Supports both static resources (McpResource) and resource templates (McpResourceTemplate).
 *
 * Example usage in another plugin:
 *
 * ```php
 * use stimmt\craft\Mcp\Mcp;
 * use stimmt\craft\Mcp\events\RegisterResourcesEvent;
 * use yii\base\Event;
 *
 * Event::on(
 *     Mcp::class,
 *     Mcp::EVENT_REGISTER_RESOURCES,
 *     function(RegisterResourcesEvent $event) {
 *         $event->addResource(MyPluginResources::class, 'my-plugin');
 *     }
 * );
 * ```
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class RegisterResourcesEvent extends Event {
    /**
     * Reserved sources that external plugins cannot use.
     */
    private const array RESERVED_SOURCES = ['core', 'craft-mcp', 'mcp'];

    /**
     * Registered resource classes grouped by source.
     * @var array<string, string[]> ['source' => ['ResourceClass1', 'ResourceClass2']]
     */
    private array $resources = [];

    /**
     * Registered resource definitions by URI (static) or name (templates).
     * @var array<string, ResourceDefinition>
     */
    private array $definitions = [];

    /**
     * Validation errors encountered during registration.
     * @var string[]
     */
    private array $errors = [];

    /**
     * Register a resource class with validation.
     *
     * @param string $class Fully qualified class name
     * @param string $source Source identifier (plugin handle) for namespacing
     * @throws InvalidArgumentException If class is invalid
     */
    public function addResource(string $class, string $source = 'plugin'): void {
        // Protect reserved sources
        if (in_array($source, self::RESERVED_SOURCES, true)) {
            $this->errors[] = "[{$source}] Source '{$source}' is reserved for core resources. Use your plugin handle.";

            return;
        }

        $this->registerResourceClass($class, $source);
    }

    /**
     * Register core resource classes (internal use only).
     *
     * This method bypasses source validation and uses 'core' as the source.
     *
     * @param string[] $classes Array of fully qualified class names
     * @internal
     */
    public function addCoreResources(array $classes): void {
        foreach ($classes as $class) {
            $this->registerResourceClass($class, 'core');
        }
    }

    /**
     * Get all registered resource classes.
     *
     * @return array<string, string[]>
     */
    public function getResources(): array {
        return $this->resources;
    }

    /**
     * Get flat list of all resource classes.
     *
     * @return string[]
     */
    public function getAllResourceClasses(): array {
        $classes = [];
        foreach ($this->resources as $sourceResources) {
            $classes = array_merge($classes, $sourceResources);
        }

        return $classes;
    }

    /**
     * Get all resource definitions.
     *
     * @return array<string, ResourceDefinition>
     */
    public function getDefinitions(): array {
        return $this->definitions;
    }

    /**
     * Get static resource definitions only.
     *
     * @return ResourceDefinition[]
     */
    public function getStaticDefinitions(): array {
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
     * Register a resource class and extract its definitions.
     */
    private function registerResourceClass(string $class, string $source): void {
        $error = $this->validateResourceClass($class);
        if ($error !== null) {
            $this->errors[] = "[{$source}] {$error}";

            return;
        }

        // After validation, class is guaranteed to exist
        /** @var class-string $class */

        // Check class-level condition
        if (is_subclass_of($class, ConditionalProvider::class) && !$class::isAvailable()) {
            return;
        }

        // Store class for backwards compatibility
        if (!isset($this->resources[$source])) {
            $this->resources[$source] = [];
        }
        $this->resources[$source][] = $class;

        // Extract and store definitions
        foreach ($this->extractResourceDefinitions($class, $source) as $definition) {
            // Use URI for static resources, name for templates
            $key = $definition->isTemplate ? $definition->name : $definition->uri;
            $this->definitions[$key] = $definition;
        }
    }

    /**
     * Extract resource definitions from a class.
     *
     * @param class-string $class
     * @return ResourceDefinition[]
     */
    private function extractResourceDefinitions(string $class, string $source): array {
        $definitions = [];

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Check for McpResource (static resource)
            $resourceAttrs = $method->getAttributes(McpResource::class);
            if (!empty($resourceAttrs)) {
                $definitions[] = $this->createStaticResourceDefinition(
                    $resourceAttrs[0]->newInstance(),
                    $method,
                    $class,
                    $source,
                );
                continue;
            }

            // Check for McpResourceTemplate (dynamic resource)
            $templateAttrs = $method->getAttributes(McpResourceTemplate::class);
            if (!empty($templateAttrs)) {
                $definitions[] = $this->createTemplateResourceDefinition(
                    $templateAttrs[0]->newInstance(),
                    $method,
                    $class,
                    $source,
                );
            }
        }

        return $definitions;
    }

    /**
     * Create a ResourceDefinition for a static resource.
     */
    private function createStaticResourceDefinition(
        McpResource $mcpResource,
        ReflectionMethod $method,
        string $class,
        string $source,
    ): ResourceDefinition {
        // Get optional McpResourceMeta attribute
        $metaAttrs = $method->getAttributes(McpResourceMeta::class);
        $meta = $metaAttrs === [] ? null : $metaAttrs[0]->newInstance();

        return new ResourceDefinition(
            uri: $mcpResource->uri,
            name: $mcpResource->name ?? $method->getName(),
            description: $mcpResource->description ?? '',
            class: $class,
            method: $method->getName(),
            source: $source,
            category: $meta?->category->value ?? ResourceCategory::GENERAL->value,
            isTemplate: false,
            mimeType: $mcpResource->mimeType,
            condition: $meta?->condition,
            completionProviders: [],
        );
    }

    /**
     * Create a ResourceDefinition for a resource template.
     */
    private function createTemplateResourceDefinition(
        McpResourceTemplate $mcpTemplate,
        ReflectionMethod $method,
        string $class,
        string $source,
    ): ResourceDefinition {
        // Get optional McpResourceMeta attribute
        $metaAttrs = $method->getAttributes(McpResourceMeta::class);
        $meta = $metaAttrs === [] ? null : $metaAttrs[0]->newInstance();

        // Extract completion providers from method parameters
        $completionProviders = $this->extractCompletionProviders($method);

        return new ResourceDefinition(
            uri: $mcpTemplate->uriTemplate,
            name: $mcpTemplate->name ?? $method->getName(),
            description: $mcpTemplate->description ?? '',
            class: $class,
            method: $method->getName(),
            source: $source,
            category: $meta?->category->value ?? ResourceCategory::GENERAL->value,
            isTemplate: true,
            mimeType: $mcpTemplate->mimeType,
            condition: $meta?->condition,
            completionProviders: $completionProviders,
        );
    }

    /**
     * Extract completion provider mappings from method parameters.
     *
     * @return array<string, string> Parameter name => CompletionProvider class
     */
    private function extractCompletionProviders(ReflectionMethod $method): array {
        $providers = [];

        foreach ($method->getParameters() as $param) {
            $attrs = $param->getAttributes(CompletionProvider::class);
            if (empty($attrs)) {
                continue;
            }

            $provider = $attrs[0]->newInstance();
            $providerClass = $provider->providerClass ?? $provider->provider;

            if ($providerClass !== null && is_string($providerClass)) {
                $providers[$param->getName()] = $providerClass;
            }
        }

        return $providers;
    }

    /**
     * Validate a resource class before registration.
     *
     * @return string|null Error message or null if valid
     */
    private function validateResourceClass(string $class): ?string {
        if (!class_exists($class)) {
            return "Class '{$class}' does not exist";
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            return "Cannot reflect class '{$class}': {$e->getMessage()}";
        }

        if ($reflection->isAbstract()) {
            return "Class '{$class}' is abstract and cannot be used as a resource provider";
        }

        if (!$reflection->isInstantiable()) {
            return "Class '{$class}' is not instantiable";
        }

        // Check for at least one method with McpResource or McpResourceTemplate attribute
        $hasResourceMethod = false;
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $resourceAttrs = $method->getAttributes(McpResource::class);
            $templateAttrs = $method->getAttributes(McpResourceTemplate::class);
            if (!empty($resourceAttrs) || !empty($templateAttrs)) {
                $hasResourceMethod = true;
                break;
            }
        }

        if (!$hasResourceMethod) {
            return "Class '{$class}' has no public methods with #[McpResource] or #[McpResourceTemplate] attribute";
        }

        return null;
    }
}
