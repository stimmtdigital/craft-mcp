<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use ReflectionAttribute;
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

        $definitions = $this->extractResourceDefinitions($class, $source);
        if ($definitions === []) {
            $this->errors[] = "[{$source}] Class '{$class}' has no public methods with #[McpResource] or #[McpResourceTemplate] attribute";

            return;
        }

        // Store class for backwards compatibility
        if (!isset($this->resources[$source])) {
            $this->resources[$source] = [];
        }
        $this->resources[$source][] = $class;

        foreach ($definitions as $definition) {
            // Use URI for static resources, name for templates
            $key = $definition->isTemplate ? $definition->name : $definition->uri;
            $this->definitions[$key] = $definition;
        }
    }

    /**
     * Extract resource definitions from a class.
     *
     * A class-level attribute is honoured first, dispatching through __invoke
     * with the class short name as the default resource name, because that is
     * the invokable shape the SDK's own discoverer supports and ours used to
     * produce no definition for at all.
     *
     * @param class-string $class
     * @return ResourceDefinition[]
     */
    private function extractResourceDefinitions(string $class, string $source): array {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        $classLevel = $this->classLevelDefinition($reflection, $source);
        if ($classLevel !== null) {
            return [$classLevel];
        }

        $definitions = [];
        foreach ($this->dispatchableMethods($reflection) as $method) {
            $definition = $this->methodDefinition($method, $source, $method->getName());
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * The definition a class-level #[McpResource] or #[McpResourceTemplate]
     * declares, dispatched through __invoke, or null when there is none.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function classLevelDefinition(ReflectionClass $reflection, string $source): ?ResourceDefinition {
        $invoke = $this->invokable($reflection);
        if ($invoke === null) {
            return null;
        }

        $resourceAttrs = $reflection->getAttributes(McpResource::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($resourceAttrs !== []) {
            return $this->staticDefinition($resourceAttrs[0]->newInstance(), $invoke, $source, $reflection->getShortName());
        }

        $templateAttrs = $reflection->getAttributes(McpResourceTemplate::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($templateAttrs !== []) {
            return $this->templateDefinition($templateAttrs[0]->newInstance(), $invoke, $source, $reflection->getShortName());
        }

        return null;
    }

    /**
     * The definition a method-level attribute declares, or null when it carries
     * neither of the two.
     */
    private function methodDefinition(ReflectionMethod $method, string $source, string $defaultName): ?ResourceDefinition {
        $resourceAttrs = $method->getAttributes(McpResource::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($resourceAttrs !== []) {
            return $this->staticDefinition($resourceAttrs[0]->newInstance(), $method, $source, $defaultName);
        }

        $templateAttrs = $method->getAttributes(McpResourceTemplate::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($templateAttrs !== []) {
            return $this->templateDefinition($templateAttrs[0]->newInstance(), $method, $source, $defaultName);
        }

        return null;
    }

    /**
     * Create a ResourceDefinition for a static resource.
     */
    private function staticDefinition(
        McpResource $mcpResource,
        ReflectionMethod $method,
        string $source,
        string $defaultName,
    ): ResourceDefinition {
        $meta = $this->houseMeta($method);

        return new ResourceDefinition(
            uri: $mcpResource->uri,
            name: $mcpResource->name ?? $defaultName,
            description: $mcpResource->description ?? '',
            class: $method->getDeclaringClass()->getName(),
            method: $method->getName(),
            source: $source,
            category: $meta?->category->value ?? ResourceCategory::GENERAL->value,
            isTemplate: false,
            mimeType: $mcpResource->mimeType,
            condition: $meta?->condition,
            completionProviders: [],
            title: $mcpResource->title,
            annotations: $mcpResource->annotations,
            size: $mcpResource->size,
            icons: $mcpResource->icons,
            meta: $mcpResource->meta,
        );
    }

    /**
     * Create a ResourceDefinition for a resource template. Templates carry no
     * size and no icons; #[McpResourceTemplate] declares neither.
     */
    private function templateDefinition(
        McpResourceTemplate $mcpTemplate,
        ReflectionMethod $method,
        string $source,
        string $defaultName,
    ): ResourceDefinition {
        $meta = $this->houseMeta($method);

        return new ResourceDefinition(
            uri: $mcpTemplate->uriTemplate,
            name: $mcpTemplate->name ?? $defaultName,
            description: $mcpTemplate->description ?? '',
            class: $method->getDeclaringClass()->getName(),
            method: $method->getName(),
            source: $source,
            category: $meta?->category->value ?? ResourceCategory::GENERAL->value,
            isTemplate: true,
            mimeType: $mcpTemplate->mimeType,
            condition: $meta?->condition,
            completionProviders: $this->extractCompletionProviders($method),
            title: $mcpTemplate->title,
            annotations: $mcpTemplate->annotations,
            meta: $mcpTemplate->meta,
        );
    }

    /**
     * Our own policy metadata for the method, when it declares any.
     */
    private function houseMeta(ReflectionMethod $method): ?McpResourceMeta {
        $metaAttrs = $method->getAttributes(McpResourceMeta::class, ReflectionAttribute::IS_INSTANCEOF);

        return $metaAttrs === [] ? null : $metaAttrs[0]->newInstance();
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
     * __invoke. Accepting a method it skips advertises a resource that
     * resources/read then cannot resolve.
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
     * Extract completion provider mappings from method parameters.
     *
     * @return array<string, string> Parameter name => CompletionProvider class
     */
    private function extractCompletionProviders(ReflectionMethod $method): array {
        $providers = [];

        foreach ($method->getParameters() as $param) {
            $attrs = $param->getAttributes(CompletionProvider::class, ReflectionAttribute::IS_INSTANCEOF);
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
     * Validate a resource class before registration. Whether it carries any
     * usable attribute is answered by extraction itself, so the two never
     * disagree about which methods count.
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

        return null;
    }
}
