<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use stimmt\craft\Mcp\attributes\McpPromptMeta;
use stimmt\craft\Mcp\contracts\ConditionalProvider;
use stimmt\craft\Mcp\enums\PromptCategory;
use stimmt\craft\Mcp\models\PromptDefinition;
use yii\base\Event;

/**
 * Event fired to allow other plugins to register MCP prompts.
 *
 * Example usage in another plugin:
 *
 * ```php
 * use stimmt\craft\Mcp\Mcp;
 * use stimmt\craft\Mcp\events\RegisterPromptsEvent;
 * use yii\base\Event;
 *
 * Event::on(
 *     Mcp::class,
 *     Mcp::EVENT_REGISTER_PROMPTS,
 *     function(RegisterPromptsEvent $event) {
 *         $event->addPrompt(MyPluginPrompts::class, 'my-plugin');
 *     }
 * );
 * ```
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class RegisterPromptsEvent extends Event {
    /**
     * Reserved sources that external plugins cannot use.
     */
    private const array RESERVED_SOURCES = ['core', 'craft-mcp', 'mcp'];

    /**
     * Registered prompt classes grouped by source.
     * @var array<string, string[]> ['source' => ['PromptClass1', 'PromptClass2']]
     */
    private array $prompts = [];

    /**
     * Registered prompt definitions by name.
     * @var array<string, PromptDefinition>
     */
    private array $definitions = [];

    /**
     * Validation errors encountered during registration.
     * @var string[]
     */
    private array $errors = [];

    /**
     * Register a prompt class with validation.
     *
     * @param string $class Fully qualified class name
     * @param string $source Source identifier (plugin handle) for namespacing
     * @throws InvalidArgumentException If class is invalid
     */
    public function addPrompt(string $class, string $source = 'plugin'): void {
        // Protect reserved sources
        if (in_array($source, self::RESERVED_SOURCES, true)) {
            $this->errors[] = "[{$source}] Source '{$source}' is reserved for core prompts. Use your plugin handle.";

            return;
        }

        $this->registerPromptClass($class, $source);
    }

    /**
     * Register core prompt classes (internal use only).
     *
     * This method bypasses source validation and uses 'core' as the source.
     *
     * @param string[] $classes Array of fully qualified class names
     * @internal
     */
    public function addCorePrompts(array $classes): void {
        foreach ($classes as $class) {
            $this->registerPromptClass($class, 'core');
        }
    }

    /**
     * Get all registered prompt classes.
     *
     * @return array<string, string[]>
     */
    public function getPrompts(): array {
        return $this->prompts;
    }

    /**
     * Get all prompt definitions.
     *
     * @return array<string, PromptDefinition>
     */
    public function getDefinitions(): array {
        return $this->definitions;
    }

    /**
     * Get prompt definitions grouped by source.
     *
     * @return array<string, PromptDefinition[]>
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
     * Register a prompt class and extract its definitions.
     */
    private function registerPromptClass(string $class, string $source): void {
        $error = $this->validatePromptClass($class);
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

        $definitions = $this->extractPromptDefinitions($class, $source);
        if ($definitions === []) {
            $this->errors[] = "[{$source}] Class '{$class}' has no public methods with #[McpPrompt] attribute";

            return;
        }

        // Store class for backwards compatibility
        $this->prompts[$source] ??= [];
        $this->prompts[$source][] = $class;

        foreach ($definitions as $definition) {
            $this->definitions[$definition->name] = $definition;
        }
    }

    /**
     * Extract prompt definitions from a class.
     *
     * A class-level attribute is honoured first, dispatching through __invoke
     * with the class short name as the default prompt name, because that is the
     * invokable shape the SDK's own discoverer supports and ours used to produce
     * no definition for at all.
     *
     * @param class-string $class
     * @return PromptDefinition[]
     */
    private function extractPromptDefinitions(string $class, string $source): array {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        $invoke = $this->invokable($reflection);
        $classAttrs = $invoke === null
            ? []
            : $reflection->getAttributes(McpPrompt::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($invoke !== null && $classAttrs !== []) {
            return [$this->promptDefinition($classAttrs[0]->newInstance(), $invoke, $source, $reflection->getShortName())];
        }

        $definitions = [];
        foreach ($this->dispatchableMethods($reflection) as $method) {
            $attrs = $method->getAttributes(McpPrompt::class, ReflectionAttribute::IS_INSTANCEOF);
            if ($attrs === []) {
                continue;
            }

            $definitions[] = $this->promptDefinition($attrs[0]->newInstance(), $method, $source, $method->getName());
        }

        return $definitions;
    }

    /**
     * Build one definition from the SDK attribute plus our own policy metadata.
     */
    private function promptDefinition(McpPrompt $mcpPrompt, ReflectionMethod $method, string $source, string $defaultName): PromptDefinition {
        $metaAttrs = $method->getAttributes(McpPromptMeta::class, ReflectionAttribute::IS_INSTANCEOF);
        $promptMeta = $metaAttrs === [] ? null : $metaAttrs[0]->newInstance();

        return new PromptDefinition(
            name: $mcpPrompt->name ?? $defaultName,
            description: $mcpPrompt->description ?? '',
            class: $method->getDeclaringClass()->getName(),
            method: $method->getName(),
            source: $source,
            category: $promptMeta?->category->value ?? PromptCategory::GENERAL->value,
            condition: $promptMeta?->condition,
            completionProviders: $this->extractCompletionProviders($method),
            title: $mcpPrompt->title,
            icons: $mcpPrompt->icons,
            meta: $mcpPrompt->meta,
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
     * __invoke. Accepting a method it skips advertises a prompt that prompts/get
     * then cannot resolve.
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
     * Validate a prompt class before registration. Whether it carries any usable
     * attribute is answered by extraction itself, so the two never disagree
     * about which methods count.
     *
     * @return string|null Error message or null if valid
     */
    private function validatePromptClass(string $class): ?string {
        if (!class_exists($class)) {
            return "Class '{$class}' does not exist";
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            return "Cannot reflect class '{$class}': {$e->getMessage()}";
        }

        if ($reflection->isAbstract()) {
            return "Class '{$class}' is abstract and cannot be used as a prompt provider";
        }

        if (!$reflection->isInstantiable()) {
            return "Class '{$class}' is not instantiable";
        }

        return null;
    }
}
