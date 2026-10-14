<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\Mcp;

/**
 * Whether a user may still hold an OAuth grant at a scope: an active account,
 * the permission that lets them connect apps at all, and a scope ScopePolicy
 * offers them today. Asked at consent and again on every token exchange, so a
 * grant cannot outlive the standing it was approved under.
 *
 * @author stimmt.digital
 */
final class OAuthPolicy {
    public static function mayHold(int $userId, Scope $scope): bool {
        $user = Craft::$app->getUsers()->getUserById($userId);
        if ($user === null || !$user->enabled || $user->suspended || $user->locked) {
            return false;
        }

        if (!$user->can('manageOwnMcpTokens') && !$user->can('manageAllMcpTokens')) {
            return false;
        }

        return in_array($scope, ScopePolicy::allowed($user, Mcp::settings()->disabledScopes), true);
    }
}
