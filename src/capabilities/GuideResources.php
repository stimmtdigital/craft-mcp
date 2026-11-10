<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Enum\Role;

/**
 * Guides served to agents as MCP resources, read from the package's docs so
 * the guide an agent reads is the same text a developer reads.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class GuideResources {
    #[McpResource(
        uri: 'stimmt://guides/tools',
        name: 'stimmt_tools_guide',
        description: 'How the plugin\'s stimmt_ tools work with Craft\'s own: natural keys to ids, counting, preview links, validated publishing, Matrix blocks, multi-site copies and the developer tools.',
        mimeType: 'text/markdown',
        annotations: new Annotations(audience: [Role::Assistant], priority: 0.8),
    )]
    public function tools(): string {
        $path = dirname(__DIR__, 2) . '/docs/agent-guide.md';
        $content = @file_get_contents($path);

        return $content === false
            ? throw new ResourceReadException('The agent guide is missing from this installation.')
            : $content;
    }
}
