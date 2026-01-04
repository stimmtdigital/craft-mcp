<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Craft;
use craft\models\CategoryGroup;
use craft\services\Categories;

/**
 * Provides completion values for category group handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class CategoryGroupHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        /** @var Categories $categoriesService */
        $categoriesService = Craft::$app->getCategories();

        /** @var CategoryGroup[] $groups */
        $groups = $categoriesService->getAllGroups();

        return array_values(array_map(
            fn (CategoryGroup $group): string => $group->handle ?? '',
            $groups,
        ));
    }
}
