<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\attributes;

use Attribute;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * Additional metadata for MCP tools.
 *
 * Use alongside the #[McpTool] attribute to add category, dangerous flag,
 * and conditional availability to your tools.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
#[Attribute(Attribute::TARGET_METHOD)]
class McpToolMeta {
    public function __construct(
        /**
         * The category this tool belongs to.
         * Use ToolCategory enum values or custom strings.
         */
        public string $category = ToolCategory::GENERAL->value,

        /**
         * Whether this tool is considered dangerous.
         * Dangerous tools require enableDangerousTools setting to be true.
         */
        public bool $dangerous = false,

        /**
         * Method name to call for conditional availability.
         * The method must exist on the same class and return bool.
         * If null, the tool is always available (subject to class-level conditions).
         */
        public ?string $condition = null,
    ) {}
}
