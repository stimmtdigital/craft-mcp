<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use InvalidArgumentException;
use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
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
     * Get flat list of all prompt classes.
     *
     * @return string[]
     */
    public function getAllPromptClasses(): array {
        $classes = [];
        foreach ($this->prompts as $sourcePrompts) {
            $classes = array_merge($classes, $sourcePrompts);
        }

        return $classes;
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
     * Get prompt definitions grouped by category.
     *
     * @return array<string, PromptDefinition[]>
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

        // Store class for backwards compatibility
        if (!isset($this->prompts[$source])) {
            $this->prompts[$source] = [];
        }
        $this->prompts[$source][] = $class;

        // Extract and store definitions
        foreach ($this->extractPromptDefinitions($class, $source) as $definition) {
            $this->definitions[$definition->name] = $definition;
        }
    }

    /**
     * Extract prompt definitions from a class.
     *
     * @param class-string $class
     * @return PromptDefinition[]
     */
    private function extractPromptDefinitions(string $class, string $source): array {
        $definitions = [];

        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $mcpPromptAttrs = $method->getAttributes(McpPrompt::class);
            if (empty($mcpPromptAttrs)) {
                continue;
            }

            $mcpPrompt = $mcpPromptAttrs[0]->newInstance();

            // Get optional McpPromptMeta attribute
            $metaAttrs = $method->getAttributes(McpPromptMeta::class);
            $meta = empty($metaAttrs) ? null : $metaAttrs[0]->newInstance();

            // Extract completion providers from method parameters
            $completionProviders = $this->extractCompletionProviders($method);

            $definitions[] = new PromptDefinition(
                name: $mcpPrompt->name ?? $method->getName(),
                description: $mcpPrompt->description ?? '',
                class: $class,
                method: $method->getName(),
                source: $source,
                category: $meta?->category->value ?? PromptCategory::GENERAL->value,
                condition: $meta?->condition,
                completionProviders: $completionProviders,
            );
        }

        return $definitions;
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
     * Validate a prompt class before registration.
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

        // Check for at least one method with McpPrompt attribute
        $hasPromptMethod = false;
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(McpPrompt::class);
            if (!empty($attributes)) {
                $hasPromptMethod = true;
                break;
            }
        }

        if (!$hasPromptMethod) {
            return "Class '{$class}' has no public methods with #[McpPrompt] attribute";
        }

        return null;
    }
}
