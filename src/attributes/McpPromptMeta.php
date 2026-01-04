<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\attributes;

use Attribute;
use stimmt\craft\Mcp\enums\PromptCategory;

/**
 * Additional metadata for MCP prompts.
 *
 * Use alongside the #[McpPrompt] attribute to add category
 * and conditional availability to your prompts.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
#[Attribute(Attribute::TARGET_METHOD)]
class McpPromptMeta {
    public function __construct(
        /**
         * The category this prompt belongs to.
         */
        public PromptCategory $category = PromptCategory::GENERAL,

        /**
         * Method name to call for conditional availability.
         * The method must exist on the same class and return bool.
         * If null, the prompt is always available (subject to class-level conditions).
         */
        public ?string $condition = null,
    ) {
    }
}
