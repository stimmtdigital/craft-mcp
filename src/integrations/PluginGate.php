<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations;

use Craft;

/**
 * Whether a third-party plugin is there to be read: installed, enabled, and
 * on the major line its tools were written against.
 *
 * WHY the version as well as the switch: these tools read the plugins' own
 * services and tables, which change shape across majors. A tool registered
 * against a version it does not understand fails on its first call, which is
 * worse than not being offered at all.
 */
final class PluginGate {
    public static function enabledAtLeast(string $handle, string $minimumVersion): bool {
        $plugins = Craft::$app->getPlugins();

        // Asked first, and alone when it says no: it is the one question a
        // disabled or missing plugin answers without loading anything.
        if (!$plugins->isPluginEnabled($handle)) {
            return false;
        }

        $version = (string) $plugins->getPlugin($handle)?->getVersion();

        return $version !== '' && version_compare($version, $minimumVersion, '>=');
    }
}
