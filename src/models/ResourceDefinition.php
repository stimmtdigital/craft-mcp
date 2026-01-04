<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use stimmt\craft\Mcp\enums\ResourceCategory;

/**
 * Value object representing an MCP resource definition with metadata.
 *
 * Handles both static resources (McpResource) and resource templates (McpResourceTemplate).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class ResourceDefinition {
    public function __construct(
        public string $uri,
        public string $name,
        public string $description,
        public string $class,
        public string $method,
        public string $source,
        public string $category,
        public bool $isTemplate = false,
        public ?string $mimeType = null,
        public ?string $condition = null,
        /** @var array<string, string> Variable name => CompletionProvider class (for templates) */
        public array $completionProviders = [],
    ) {
    }

    /**
     * Check if this resource is enabled based on its condition.
     *
     * This checks the method-level condition only.
     * Class-level conditions (ConditionalProvider) are checked during registration.
     */
    public function isConditionMet(): bool {
        if ($this->condition === null) {
            return true;
        }

        if (!class_exists($this->class)) {
            return false;
        }

        if (!method_exists($this->class, $this->condition)) {
            return false;
        }

        $instance = new ($this->class)();

        return (bool) $instance->{$this->condition}();
    }

    /**
     * Check if this is a core resource.
     */
    public function isCore(): bool {
        return $this->source === 'core';
    }

    /**
     * Check if this resource has completion providers.
     */
    public function hasCompletions(): bool {
        return $this->completionProviders !== [];
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return [
            'uri' => $this->uri,
            'name' => $this->name,
            'description' => $this->description,
            'source' => $this->source,
            'category' => $this->category,
            'isTemplate' => $this->isTemplate,
            'mimeType' => $this->mimeType,
            'hasCompletions' => $this->hasCompletions(),
        ];
    }

    /**
     * Create a ResourceDefinition from extracted metadata.
     *
     * @param array{uri?: string, name?: string, description?: string, class?: string, method?: string, source?: string, category?: string, isTemplate?: bool, mimeType?: string|null, condition?: string|null, completionProviders?: array<string, string>} $data
     */
    public static function fromArray(array $data): self {
        return new self(
            uri: $data['uri'] ?? '',
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            class: $data['class'] ?? '',
            method: $data['method'] ?? '',
            source: $data['source'] ?? 'plugin',
            category: $data['category'] ?? ResourceCategory::GENERAL->value,
            isTemplate: $data['isTemplate'] ?? false,
            mimeType: $data['mimeType'] ?? null,
            condition: $data['condition'] ?? null,
            completionProviders: $data['completionProviders'] ?? [],
        );
    }
}
