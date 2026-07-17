<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\behaviors\CustomFieldBehavior;
use craft\db\Query;
use craft\db\Table;
use craft\models\FieldLayout;
use Mcp\Server\RequestContext;
use Throwable;

/**
 * Detects external project config changes before a tool runs and refreshes
 * the long-lived server's in-process state: cached Info, ProjectConfig, and
 * the schema memos this plugin reads through (fields, field layouts,
 * sections and entry types, sites, volumes, category groups, global sets),
 * the compiled CustomFieldBehavior handle map, and the GraphQL schema
 * caches. Proactive counterpart to Craft's stale check: the same
 * configVersion comparison _acquireLock() uses to throw
 * StaleResourceException, done first so writes succeed and reads are fresh.
 * Fail-open by design: a failing probe must never block a tool call.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ConfigFreshness {
    /**
     * Static config resources this refresh can invalidate, kept in sync with
     * the #[McpResource] URIs declared on ConfigResources. A refresh cannot
     * tell which of these actually changed, so every subscriber to any of
     * them gets a chance to re-read.
     *
     * @var string[]
     */
    private const array CONFIG_RESOURCE_URIS = [
        'craft://config/general',
        'craft://config/routes',
        'craft://config/sites',
        'craft://config/volumes',
        'craft://config/plugins',
    ];

    /**
     * $context is optional and only supplied by call sites that thread a
     * RequestContext through SafeExecution::run(); without it a detected
     * refresh still happens, it just has no session to notify.
     */
    public static function ensure(?RequestContext $context = null): void {
        try {
            if (!self::applicable()) {
                return;
            }

            $loaded = Craft::$app->getInfo()->configVersion;
            if (self::isStale(self::storedVersion(), $loaded)) {
                self::refresh($context);
            }
        } catch (Throwable $e) {
            Craft::warning('Config freshness probe failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    public static function isStale(?string $stored, ?string $loaded): bool {
        return $stored !== null && $loaded !== null && $stored !== $loaded;
    }

    private static function applicable(): bool {
        // Order matters: these short-circuits keep ensure()'s catch (and its
        // Craft::warning call) unreachable under a null or stubbed app in
        // unit tests.
        return Craft::$app !== null
            && method_exists(Craft::$app, 'getInfo')
            && Craft::$app->getIsInstalled();
    }

    private static function storedVersion(): ?string {
        $version = (new Query())->select(['configVersion'])->from([Table::INFO])->scalar();

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function refresh(?RequestContext $context): void {
        PluginReloader::resetProjectConfig();
        Craft::$app->getFields()->refreshFields();
        self::syncCustomFieldHandles();
        PluginReloader::resetFieldLayoutsMemo();
        PluginReloader::resetEntriesMemos();
        Craft::$app->getSites()->refreshSites();
        Craft::$app->getGlobals()->reset();
        Craft::$app->getGql()->flushCaches();
        PluginReloader::resetVolumesMemo();
        PluginReloader::resetCategoriesMemo();

        // Last on purpose: getIsInstalled(true) nulls the cached Info model,
        // which is what makes the next stale check compare a fresh
        // configVersion. If an earlier step ever throws, the version still
        // mismatches on the next call and the refresh retries itself.
        Craft::$app->getIsInstalled(true);
        Craft::info('Project config changed externally; refreshed in-process state', __METHOD__);

        self::notifySubscribers($context);
    }

    /**
     * A refresh cannot tell which config resource changed, so it offers
     * every one of them to the subscription check; ResourceChangeNotifier
     * only actually sends to URIs the session subscribed to.
     */
    private static function notifySubscribers(?RequestContext $context): void {
        if ($context === null) {
            return;
        }

        foreach (self::CONFIG_RESOURCE_URIS as $uri) {
            ResourceChangeNotifier::notify($context, $uri);
        }
    }

    /**
     * Craft's compiled CustomFieldBehavior class cannot be redefined in a
     * running process, but its handle map is a public static. Mirror the
     * runtime patch Craft itself applies for in-process field saves
     * (Fields::saveLayout / saveField) so handles added by another process
     * become visible without a restart.
     */
    private static function syncCustomFieldHandles(): void {
        $handles = (new Query())->select(['handle'])->from(Table::FIELDS)->column();

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            $handles = array_merge($handles, self::layoutHandleOverrides($layout));
        }

        self::patchHandles(array_values(array_filter($handles, is_string(...))));
    }

    /**
     * @return string[]
     */
    private static function layoutHandleOverrides(FieldLayout $layout): array {
        $overrides = [];

        foreach ($layout->getCustomFieldElements() as $element) {
            if (isset($element->handle)) {
                $overrides[] = $element->handle;
            }
        }

        return $overrides;
    }

    /**
     * @param string[] $handles
     */
    public static function patchHandles(array $handles): void {
        foreach ($handles as $handle) {
            CustomFieldBehavior::$fieldHandles[$handle] = true;
        }
    }
}
