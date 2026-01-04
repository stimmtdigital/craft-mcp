<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use craft\elements\Category;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\SafeExecution;

/**
 * Category MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class CategoryTools {
    /**
     * List categories.
     */
    #[McpTool(
        name: 'list_categories',
        description: 'List categories from Craft CMS. Filter by group handle.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listCategories(?string $group = null, int $limit = 100, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($group, $limit): array {
            $query = Category::find()->limit($limit);

            if ($group !== null) {
                $query->group($group);
            }

            $categories = $query->all();
            $results = array_map($this->serializeCategory(...), $categories);

            return Response::list('categories', $results);
        });
    }

    /**
     * Serialize a category to array.
     */
    private function serializeCategory(Category $category): array {
        return [
            'id' => $category->id,
            'title' => $category->title,
            'slug' => $category->slug,
            'level' => $category->level,
            'groupId' => $category->groupId,
            'groupHandle' => $category->getGroup()->handle,
            'url' => $category->getUrl(),
        ];
    }
}
