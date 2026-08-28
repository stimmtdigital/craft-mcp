<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use craft\elements\User;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\HandleResolver;
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
        title: 'Browse users',
        description: 'List users from Craft CMS. Filter by group handle, status, email.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listUsers(
        #[Schema(description: 'User group handle. Omit to list users from every group.')]
        ?string $group = null,
        #[Schema(description: 'Account status: active, pending, suspended, locked, or inactive.')]
        ?string $status = null,
        #[Schema(description: 'Exact email address to match.')]
        ?string $email = null,
        int $limit = 50,
        ?RequestContext $context = null,
    ): array {
        $groupModel = HandleResolver::userGroup($group);
        $query = User::find()->limit($limit);

        // By id, not by handle: Craft's own group() hands a string it could
        // not resolve to a helper that only takes arrays, so the parameter
        // died in a vendor TypeError that named a server path. Resolving the
        // handle here settles it before the query ever sees it.
        if ($groupModel !== null) {
            $query->groupId($groupModel->id);
        }
        if ($status !== null) {
            $query->status(HandleResolver::userStatus($status));
        }
        if ($email !== null) {
            $query->email($email);
        }

        Authorization::scopeQuery($query);
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
