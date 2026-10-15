<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\elements\User;

/**
 * A user as the user tools report it. Shared so that what a user write hands
 * back is exactly what list_users would read.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class UserSerializer {
    public static function serialize(User $user): array {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'fullName' => $user->fullName,
            'admin' => $user->admin,
            'status' => $user->getStatus(),
            'groups' => array_map(fn ($g) => $g->handle, $user->getGroups()),
            'lastLoginDate' => $user->lastLoginDate?->format('Y-m-d H:i:s'),
            'dateCreated' => $user->dateCreated?->format('Y-m-d H:i:s'),
        ];
    }
}
