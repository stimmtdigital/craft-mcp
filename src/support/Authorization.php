<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\services\Elements;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Per-request acting-user authorization. Activated only for content-scope
 * HTTP tokens; everywhere else (stdio, readonly, full) every assertion is a
 * no-op. Element checks run through Craft's element authorization (the same
 * canSave/canDelete logic the control panel enforces) and work for ANY
 * element type, so multi-site, peer, and draft nuances come from core, live
 * per request. assertCan() covers raw permission strings for future
 * non-element tools (schema, fields). Entry writes are the only consumers
 * today; the seam is deliberately wider.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Authorization {
    private static ?User $user = null;

    public static function enforceFor(User $user): void {
        self::$user = $user;
    }

    public static function reset(): void {
        self::$user = null;
    }

    public static function enforced(): bool {
        return self::$user !== null;
    }

    public static function assertCanSave(ElementInterface $element): void {
        self::assert($element, 'save', fn ($elements) => $elements->canSave($element, self::$user));
    }

    public static function assertCanPublish(ElementInterface $element): void {
        // Mirrors the control panel's apply-draft gate (ElementsController):
        // the user must be allowed to save this element itself (for drafts:
        // own draft or the peer-draft permission) AND its canonical version.
        self::assert($element, 'publish', fn ($elements) => $elements->canSave($element, self::$user)
            && $elements->canSaveCanonical($element, self::$user));
    }

    public static function assertCanDelete(ElementInterface $element): void {
        self::assert($element, 'delete', fn ($elements) => $elements->canDelete($element, self::$user));
    }

    public static function assertCanDuplicate(ElementInterface $element): void {
        self::assert($element, 'duplicate', fn ($elements) => $elements->canView($element, self::$user)
            && $elements->canDuplicateAsDraft($element, self::$user));
    }

    public static function assertCanView(ElementInterface $element): void {
        self::assert($element, 'view', fn ($elements) => $elements->canView($element, self::$user));
    }

    /**
     * Raw permission gate for non-element operations (future schema and
     * field tools). $action feeds the error message: "not allowed to
     * {$action}".
     */
    public static function assertCan(string $permission, string $action): void {
        if (self::$user === null || self::$user->can($permission)) {
            return;
        }

        throw new ToolCallException(
            "This token's user '" . self::$user->username . "' is not allowed to {$action}"
            . " (missing Craft permission '{$permission}', checked live). Ask an admin to widen the"
            . " user's permissions or mint a token for a user who has them.",
        );
    }

    /**
     * @param callable(Elements):bool $check
     */
    private static function assert(ElementInterface $element, string $action, callable $check): void {
        if (self::$user === null) {
            return;
        }

        if ($check(Craft::$app->getElements())) {
            return;
        }

        // The refusal message must never be masked by a broken element
        // (an unset siteId throws inside getSite()).
        try {
            $site = $element->getSite()->handle;
        } catch (Throwable) {
            $site = 'unknown';
        }
        $noun = $element::lowerDisplayName();

        throw new ToolCallException(
            "This token's user '" . self::$user->username . "' is not allowed to {$action} this {$noun}"
            . " on site '{$site}' (Craft user permissions, checked live). Ask an admin to widen the user's"
            . ' permissions or mint a token for a user who has them.',
        );
    }
}
