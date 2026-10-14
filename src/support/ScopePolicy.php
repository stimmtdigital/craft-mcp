<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\elements\User;
use stimmt\craft\Mcp\http\Scope;

/**
 * Which scopes a user may hold a token for: the one rule behind the token
 * screen's dropdown, its create and regenerate gates, and the OAuth consent
 * screen, so what is offered and what is accepted can never disagree (#48).
 *
 * @author stimmt.digital
 */
final class ScopePolicy {
    /**
     * Full scope bypasses all read and write authorization and includes code
     * execution, so only Craft's real admin flag may grant it; the
     * manageAllMcpTokens permission alone is not enough (#48).
     */
    public static function fullAllowed(?User $user): bool {
        return $user->admin ?? false;
    }

    /**
     * The self-service pair, Full for admins, minus anything in the
     * disabledScopes setting, which beats every permission, admin included.
     *
     * @param string[] $disabledScopes
     * @return Scope[]
     */
    public static function allowed(?User $user, array $disabledScopes): array {
        $scopes = [Scope::ReadOnly, Scope::Content];
        if (self::fullAllowed($user)) {
            $scopes[] = Scope::Full;
        }

        return array_values(array_filter(
            $scopes,
            static fn (Scope $scope): bool => !$scope->isDisabled($disabledScopes),
        ));
    }
}
