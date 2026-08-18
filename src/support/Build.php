<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Composer\InstalledVersions;

/**
 * Which build of this plugin is running.
 *
 * WHY not just the plugin version: the version answers "which release" only
 * when a release installed it. Install from a branch, as every contributor and
 * every staging box does, and Craft reports the constraint (`dev-main`), which
 * is one string for every commit that branch will ever have. Two deploys a week
 * apart are then indistinguishable, and a stale answer looks exactly like a
 * fresh one.
 *
 * That is a reporting annoyance in `get_mcp_info` and a real defect in the
 * discovery cache, which keys on the version outside devMode: on a
 * branch-installed site the key would never turn over, so the first deploy's
 * tool scan would be served forever. Composer records the commit it installed,
 * which does change, so the two together identify a build in both worlds.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Build {
    public const string PACKAGE = 'stimmt/craft-mcp';

    private const int SHORT_REFERENCE = 12;

    /**
     * The version Craft and the MCP handshake report: a release tag where one
     * exists, the branch constraint otherwise.
     */
    public static function version(): string {
        return self::installed()
            ? (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown')
            : 'unknown';
    }

    /**
     * The commit the running code came from, shortened. Null when composer has
     * none, which happens for an install from a dist archive with no source
     * reference; the version alone has to do in that case.
     */
    public static function reference(): ?string {
        $reference = self::installed() ? InstalledVersions::getReference(self::PACKAGE) : null;

        return $reference === null ? null : substr($reference, 0, self::SHORT_REFERENCE);
    }

    /**
     * One string identifying this build, for anything that must change when the
     * code does. Falls back to the version alone when there is no reference,
     * which is the same guarantee we had before and no worse.
     */
    public static function revision(): string {
        $reference = self::reference();

        return $reference === null ? self::version() : self::version() . '@' . $reference;
    }

    /**
     * Guarded because a plugin can be loaded in contexts where composer's
     * generated map does not know it, and this must never be the thing that
     * breaks a tool call.
     */
    private static function installed(): bool {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE);
    }
}
