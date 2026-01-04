<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * Categories for MCP prompts.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum PromptCategory: string {
    case CONTENT = 'content';
    case SCHEMA = 'schema';
    case DEBUGGING = 'debugging';
    case WORKFLOW = 'workflow';
    case GENERAL = 'general';
}
