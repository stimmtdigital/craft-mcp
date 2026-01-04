<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use stimmt\craft\Mcp\enums\PromptCategory;

/**
 * Value object representing an MCP prompt definition with metadata.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class PromptDefinition {
    public function __construct(
        public string $name,
        public string $description,
        public string $class,
        public string $method,
        public string $source,
        public string $category,
        public ?string $condition = null,
        /** @var array<string, string> Parameter name => CompletionProvider class */
        public array $completionProviders = [],
    ) {
    }

    /**
     * Check if this prompt is enabled based on its condition.
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
     * Check if this is a core prompt.
     */
    public function isCore(): bool {
        return $this->source === 'core';
    }

    /**
     * Check if this prompt has completion providers.
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
            'name' => $this->name,
            'description' => $this->description,
            'source' => $this->source,
            'category' => $this->category,
            'hasCompletions' => $this->hasCompletions(),
        ];
    }

    /**
     * Create a PromptDefinition from extracted metadata.
     *
     * @param array{name?: string, description?: string, class?: string, method?: string, source?: string, category?: string, condition?: string|null, completionProviders?: array<string, string>} $data
     */
    public static function fromArray(array $data): self {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            class: $data['class'] ?? '',
            method: $data['method'] ?? '',
            source: $data['source'] ?? 'plugin',
            category: $data['category'] ?? PromptCategory::GENERAL->value,
            condition: $data['condition'] ?? null,
            completionProviders: $data['completionProviders'] ?? [],
        );
    }
}
