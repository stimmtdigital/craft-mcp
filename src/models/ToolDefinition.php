<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use Mcp\Schema\Icon;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * Value object representing an MCP tool definition with metadata.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class ToolDefinition {
    /**
     * The trailing fields mirror #[McpTool] one for one, so an external tool
     * declaring them is advertised the way its author declared it instead of
     * falling back to the conservative destructive defaults. They are appended
     * rather than inserted, because third-party plugins construct this
     * positionally; anything added later belongs after them for the same reason.
     *
     * @param Icon[]|null $icons
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $outputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $class,
        public string $method,
        public string $source,
        public string $category,
        public bool $dangerous,
        public bool $privileged,
        public ?string $condition = null,
        public ?string $title = null,
        public ?ToolAnnotations $annotations = null,
        public ?array $icons = null,
        public ?array $meta = null,
        public ?array $outputSchema = null,
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
            'privileged' => $this->privileged,
        ];
    }

    /**
     * Create a ToolDefinition from extracted metadata. Every key is optional,
     * including the ones added after the first release of this factory.
     *
     * @param array{name?: string, description?: string, class?: string, method?: string, source?: string, category?: string, dangerous?: bool, privileged?: bool, condition?: string|null, title?: string|null, annotations?: ToolAnnotations|null, icons?: Icon[]|null, meta?: array<string, mixed>|null, outputSchema?: array<string, mixed>|null} $data
     */
    public static function fromArray(array $data): self {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            class: $data['class'] ?? '',
            method: $data['method'] ?? '',
            source: $data['source'] ?? 'plugin',
            category: $data['category'] ?? ToolCategory::GENERAL->value,
            dangerous: $data['dangerous'] ?? false,
            privileged: $data['privileged'] ?? false,
            condition: $data['condition'] ?? null,
            title: $data['title'] ?? null,
            annotations: $data['annotations'] ?? null,
            icons: $data['icons'] ?? null,
            meta: $data['meta'] ?? null,
            outputSchema: $data['outputSchema'] ?? null,
        );
    }
}
