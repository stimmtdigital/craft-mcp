<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Response;

/**
 * User MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class UserTools {
    /**
     * List users.
     */
    #[McpTool(
        name: 'list_users',
        description: 'List users from Craft CMS. Filter by group handle, status, email.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listUsers(
        ?string $group = null,
        ?string $status = null,
        ?string $email = null,
        int $limit = 50,
    ): array {
        $query = User::find()->limit($limit);

        if ($group !== null) {
            $query->group($group);
        }
        if ($status !== null) {
            $query->status($status);
        }
        if ($email !== null) {
            $query->email($email);
        }

        $users = $query->all();
        $results = array_map($this->serializeUser(...), $users);

        return Response::list('users', $results);
    }

    /**
     * Serialize a user to array.
     */
    private function serializeUser(User $user): array {
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
