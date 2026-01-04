<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * Categories for MCP tools.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum ToolCategory: string {
    case CONTENT = 'content';
    case SCHEMA = 'schema';
    case SYSTEM = 'system';
    case DATABASE = 'database';
    case DEBUGGING = 'debugging';
    case MULTISITE = 'multisite';
    case GRAPHQL = 'graphql';
    case BACKUP = 'backup';
    case COMMERCE = 'commerce';
    case CORE = 'core';
    case PLUGIN = 'plugin';
    case GENERAL = 'general';
}
