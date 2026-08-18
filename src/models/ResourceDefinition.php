<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\models;

use Mcp\Schema\Annotations;
use Mcp\Schema\Icon;
use stimmt\craft\Mcp\enums\ResourceCategory;

/**
 * Value object representing an MCP resource definition with metadata.
 *
 * Handles both static resources (McpResource) and resource templates (McpResourceTemplate).
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class ResourceDefinition {
    /**
     * The trailing fields mirror #[McpResource] one for one, so an external
     * resource declaring an audience, a priority or a size keeps them instead of
     * arriving at the client as name, description and MIME type only. Templates
     * carry no size and no icons, matching #[McpResourceTemplate], so those two
     * stay null for them. The fields are appended rather than inserted, because
     * third-party plugins construct this positionally; anything added later
     * belongs after them for the same reason.
     *
     * @param Icon[]|null $icons
     * @param array<string, mixed>|null $meta
     */
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
        public ?string $title = null,
        public ?Annotations $annotations = null,
        public ?int $size = null,
        public ?array $icons = null,
        public ?array $meta = null,
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
     * Create a ResourceDefinition from extracted metadata. Every key is
     * optional, including the ones added after the first release of this factory.
     *
     * @param array{uri?: string, name?: string, description?: string, class?: string, method?: string, source?: string, category?: string, isTemplate?: bool, mimeType?: string|null, condition?: string|null, completionProviders?: array<string, string>, title?: string|null, annotations?: Annotations|null, size?: int|null, icons?: Icon[]|null, meta?: array<string, mixed>|null} $data
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
            title: $data['title'] ?? null,
            annotations: $data['annotations'] ?? null,
            size: $data['size'] ?? null,
            icons: $data['icons'] ?? null,
            meta: $data['meta'] ?? null,
        );
    }
}
