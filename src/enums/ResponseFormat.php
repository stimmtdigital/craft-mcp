<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * Generic response format for MCP tools.
 *
 * Can be used by any tool that supports multiple output formats.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum ResponseFormat: string {
    /**
     * Structured array/JSON output for programmatic use.
     */
    case STRUCTURED = 'structured';

    /**
     * Human-readable text output via TextContent.
     */
    case TEXT = 'text';
}
