<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * Categories for MCP resources.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum ResourceCategory: string {
    case SCHEMA = 'schema';
    case CONFIG = 'config';
    case CONTENT = 'content';
    case SYSTEM = 'system';
    case GENERAL = 'general';
}
