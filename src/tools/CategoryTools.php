<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use craft\elements\Category;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\Window;

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
        title: 'Browse categories',
        description: 'List categories from Craft CMS. Filter by group handle.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listCategories(
        #[Schema(description: 'Category group handle. Omit to list categories from every group.')]
        ?string $group = null,
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 100,
        ?RequestContext $context = null,
    ): array {
        Window::assert($limit);
        $groupModel = HandleResolver::categoryGroup($group);
        $query = Category::find()->limit($limit);

        // The model rather than the handle: it carries the group's structure
        // id, which is what orders the results.
        if ($groupModel !== null) {
            $query->group($groupModel);
        }

        Authorization::scopeQuery($query);
        $categories = $query->all();
        $results = array_map($this->serializeCategory(...), $categories);

        return Response::list('categories', $results);
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
            'groupHandle' => $category->getGroup()?->handle, // @phpstan-ignore nullsafe.neverNull
            'url' => $category->getUrl(),
        ];
    }
}
