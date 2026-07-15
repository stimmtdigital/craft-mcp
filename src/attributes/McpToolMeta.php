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
         */
        public ToolCategory $category = ToolCategory::GENERAL,

        /**
         * Whether this tool is considered dangerous.
         * Dangerous tools require enableDangerousTools setting to be true.
         */
        public bool $dangerous = false,

        /**
         * Whether this tool is a privileged install-introspection read (logs,
         * config, database structure/contents, environment). Privileged tools
         * are hidden from read-scoped HTTP tokens whose user is not an admin,
         * unless the tool name is opened via the scopedTokenPrivilegedTools
         * setting. Full scope and stdio are never gated on this axis.
         */
        public bool $privileged = false,

        /**
         * Method name to call for conditional availability.
         * The method must exist on the same class and return bool.
         * If null, the tool is always available (subject to class-level conditions).
         */
        public ?string $condition = null,
    ) {
    }
}
