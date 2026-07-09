<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use craft\db\Query;
use craft\db\Table;
use Throwable;

/**
 * Detects external project config changes before a tool runs and refreshes
 * the long-lived server's in-process state (cached Info, ProjectConfig,
 * field and section memos). Proactive counterpart to Craft's stale check:
 * the same configVersion comparison _acquireLock() uses to throw
 * StaleResourceException, done first so writes succeed and reads are fresh.
 * Fail-open by design: a failing probe must never block a tool call.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ConfigFreshness {
    public static function ensure(): void {
        try {
            if (!self::applicable()) {
                return;
            }

            $loaded = Craft::$app->getInfo()->configVersion;
            if (self::isStale(self::storedVersion(), $loaded)) {
                self::refresh();
            }
        } catch (Throwable $e) {
            Craft::warning('Config freshness probe failed: ' . $e->getMessage(), __METHOD__);
        }
    }

    public static function isStale(?string $stored, ?string $loaded): bool {
        return $stored !== null && $loaded !== null && $stored !== $loaded;
    }

    private static function applicable(): bool {
        return Craft::$app !== null
            && method_exists(Craft::$app, 'getInfo')
            && Craft::$app->getIsInstalled();
    }

    private static function storedVersion(): ?string {
        $version = (new Query())->select(['configVersion'])->from([Table::INFO])->scalar();

        return is_string($version) && $version !== '' ? $version : null;
    }

    private static function refresh(): void {
        // Public API that nulls the cached Info model, so the next stale
        // check compares against a fresh configVersion.
        Craft::$app->getIsInstalled(true);
        PluginReloader::resetProjectConfig();
        Craft::$app->getFields()->refreshFields();
        PluginReloader::resetEntriesMemos();
        Craft::info('Project config changed externally; refreshed in-process state', __METHOD__);
    }
}
