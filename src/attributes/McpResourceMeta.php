<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\attributes;

use Attribute;
use stimmt\craft\Mcp\enums\ResourceCategory;

/**
 * Additional metadata for MCP resources.
 *
 * Use alongside the #[McpResource] or #[McpResourceTemplate] attribute
 * to add category and conditional availability to your resources.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
#[Attribute(Attribute::TARGET_METHOD)]
class McpResourceMeta {
    public function __construct(
        /**
         * The category this resource belongs to.
         */
        public ResourceCategory $category = ResourceCategory::GENERAL,

        /**
         * Method name to call for conditional availability.
         * The method must exist on the same class and return bool.
         * If null, the resource is always available (subject to class-level conditions).
         */
        public ?string $condition = null,
    ) {
    }
}
