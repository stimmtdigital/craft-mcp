<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Craft;
use craft\models\UserGroup;
use craft\services\UserGroups;

/**
 * Provides completion values for user group handles.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class UserGroupHandleProvider extends CraftCompletionProvider {
    /**
     * @return string[]
     */
    protected function fetchValues(): array {
        /** @var UserGroups $userGroupsService */
        $userGroupsService = Craft::$app->getUserGroups();

        /** @var UserGroup[] $groups */
        $groups = $userGroupsService->getAllGroups();

        return array_values(array_map(
            fn (UserGroup $group): string => $group->handle ?? '',
            $groups,
        ));
    }
}
