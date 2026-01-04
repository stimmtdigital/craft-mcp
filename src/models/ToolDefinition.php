<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * Value object representing an MCP tool definition with metadata.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class ToolDefinition {
    public function __construct(
        public string $name,
        public string $description,
        public string $class,
        public string $method,
        public string $source,
        public string $category,
        public bool $dangerous,
        public ?string $condition = null,
    ) {
    }

    /**
     * Check if this tool is enabled based on its condition.
     *
     * This checks the method-level condition only.
     * Class-level conditions (ConditionalToolProvider) are checked during registration.
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
     * Check if this is a core tool.
     */
    public function isCore(): bool {
        return $this->source === 'core';
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
            'dangerous' => $this->dangerous,
        ];
    }

    /**
     * Create a ToolDefinition from extracted metadata.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? '',
            class: $data['class'],
            method: $data['method'],
            source: $data['source'] ?? 'plugin',
            category: $data['category'] ?? ToolCategory::GENERAL->value,
            dangerous: $data['dangerous'] ?? false,
            condition: $data['condition'] ?? null,
        );
    }
}
