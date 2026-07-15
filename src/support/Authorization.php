<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\AssetQuery;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\db\UserQuery;
use craft\elements\User;
use craft\services\Elements;
use Mcp\Exception\ToolCallException;
use Throwable;

/**
 * Per-request acting-user authorization. Activated for readonly and content
 * HTTP tokens; everywhere else (stdio, full) every assertion and scope call is
 * a no-op. Element checks run through Craft's element authorization (the same
 * canSave/canView logic the control panel enforces) and work for ANY element
 * type, so multi-site, peer, and draft nuances come from core, live per
 * request. Three reusable seams: assertCan* for single elements, assertCan()
 * for raw permissions, and scopeQuery() to bound a list query to viewable
 * sources. New tools opt in with one call.
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
     * Restrict an element LIST query to the sources the acting user may view,
     * mirroring the control panel's per-type view permissions. No-op until
     * enforced (stdio/full). Fails loud on an unhandled query type so a new
     * element-list tool cannot ship without a deliberate scoping rule.
     */
    public static function scopeQuery(ElementQueryInterface $query): void {
        if (self::$user === null) {
            return;
        }

        match (true) {
            $query instanceof EntryQuery => self::scopeEntries($query),
            $query instanceof AssetQuery => self::scopeAssets($query),
            $query instanceof CategoryQuery => self::scopeCategories($query),
            $query instanceof UserQuery => self::scopeUsers($query),
            default => throw new ToolCallException(
                'This read has no view-scoping rule for ' . $query::class
                . '; it is not available to permission-scoped tokens yet.',
            ),
        };
    }

    private static function scopeEntries(EntryQuery $query): void {
        $handles = self::viewableHandles('viewEntries', Craft::$app->getEntries()->getAllSections());
        $handles === [] ? $query->id(0) : $query->section($handles);
    }

    private static function scopeAssets(AssetQuery $query): void {
        $handles = self::viewableHandles('viewAssets', Craft::$app->getVolumes()->getAllVolumes());
        $handles === [] ? $query->id(0) : $query->volume($handles);
    }

    private static function scopeCategories(CategoryQuery $query): void {
        $handles = self::viewableHandles('viewCategories', Craft::$app->getCategories()->getAllGroups());
        $handles === [] ? $query->id(0) : $query->group($handles);
    }

    private static function scopeUsers(UserQuery $query): void {
        // No viewUsers permission means a user may only ever see themselves.
        if (self::$user !== null && !self::$user->can('viewUsers')) {
            $query->id((int) self::$user->id);
        }
    }

    /**
     * Handles of the sources whose "{permission}:{uid}" the acting user holds.
     * An empty result is a real, safe answer; the caller constrains the query
     * to zero rows rather than leaving it unbounded.
     *
     * @param array<int, object{uid: string|null, handle: string|null}> $sources
     * @return string[]
     */
    private static function viewableHandles(string $permission, array $sources): array {
        $handles = [];
        foreach ($sources as $source) {
            if ($source->uid !== null && $source->handle !== null && self::$user?->can("{$permission}:{$source->uid}")) {
                $handles[] = $source->handle;
            }
        }

        return $handles;
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
