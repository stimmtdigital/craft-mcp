<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\resources;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Exception\ResourceReadException;
use stimmt\craft\Mcp\attributes\McpResourceMeta;
use stimmt\craft\Mcp\enums\ResourceCategory;

/**
 * Guides agents can pull on demand, served straight from the shipped docs
 * pages so there is exactly one copy of each contract to maintain.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class GuideResources {
    /**
     * The content-writing payload contract.
     */
    #[McpResource(
        uri: 'craft://guides/content-writing',
        name: 'content-writing-guide',
        description: 'The full content-writing contract for agents: payload format, natural keys, Matrix shape, draft-first workflow with the pending-drafts review queue, schema discovery with input shapes, and structured feedback.',
        mimeType: 'text/markdown',
    )]
    #[McpResourceMeta(category: ResourceCategory::CONTENT)]
    public function contentWriting(): string {
        return $this->doc('content-writing.md');
    }

    private function doc(string $file): string {
        $path = dirname(__DIR__, 2) . '/docs/' . $file;
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new ResourceReadException("Guide '{$file}' is missing from this installation");
        }

        return $content;
    }
}
