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
 * request. Four reusable seams: assertCan* for single elements, assertCan()
 * for raw permissions, scopeQuery() to bound a list query to viewable
 * sources, and assertPrivileged() to admin-lock a resource or tool outright.
 * New tools and resources opt in with one call.
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
     * Gate for privileged install-introspection resources (config, routes,
     * sites, volumes, plugins). Mirrors the McpToolMeta privileged flag the
     * tool surface uses, but resources are never filtered out of a list, so
     * the read itself refuses instead. No-op until enforced (stdio/full) and
     * a no-op for admins; $what names the resource in the denial message.
     */
    public static function assertPrivileged(string $what): void {
        if (self::$user === null || self::$user->admin) {
            return;
        }

        throw new ToolCallException(
            "This token's user '" . self::$user->username . "' is not allowed to read {$what}"
            . ' (privileged install-introspection resource, admin only, checked live). Ask an admin'
            . " to widen the user's permissions or mint a token for a user who has them.",
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
        $ids = self::viewableIds($query->sectionId, 'viewEntries', Craft::$app->getEntries()->getAllSections());
        $ids === [] ? $query->id(0) : $query->sectionId($ids);
    }

    private static function scopeAssets(AssetQuery $query): void {
        $ids = self::viewableIds($query->volumeId, 'viewAssets', Craft::$app->getVolumes()->getAllVolumes());
        $ids === [] ? $query->id(0) : $query->volumeId($ids);
    }

    private static function scopeCategories(CategoryQuery $query): void {
        $ids = self::viewableIds($query->groupId, 'viewCategories', Craft::$app->getCategories()->getAllGroups());
        $ids === [] ? $query->id(0) : $query->groupId($ids);
    }

    private static function scopeUsers(UserQuery $query): void {
        // No viewUsers permission means a user may only ever see themselves.
        if (self::$user !== null && !self::$user->can('viewUsers')) {
            $query->id((int) self::$user->id);
        }
    }

    /**
     * The source ids the acting user may view, INTERSECTED with the query's
     * existing source constraint, so a caller's own section/volume/group
     * filter can only narrow further, never widen past what the user can see.
     * A null/non-list existing constraint means no caller filter, so the full
     * viewable set applies. An empty result yields zero rows at the call site.
     *
     * @param mixed $current the query's current source-id constraint
     * @param array<int, object{uid: string|null, id: int|null}> $sources
     * @return int[]
     */
    private static function viewableIds(mixed $current, string $permission, array $sources): array {
        $viewable = [];
        foreach ($sources as $source) {
            if ($source->id !== null && $source->uid !== null && self::$user?->can("{$permission}:{$source->uid}")) {
                $viewable[] = $source->id;
            }
        }

        if (!is_array($current) || $current === []) {
            return $viewable;
        }

        return array_values(array_intersect($viewable, array_map(intval(...), $current)));
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
