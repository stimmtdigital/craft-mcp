<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;

/**
 * Verdicts on how the install is configured to run, from plain inputs so they
 * are decided the same way whether Craft is booted or not.
 */
final class EnvironmentRules {
    /**
     * Only the exact word: Craft itself attaches no meaning to any value of
     * CRAFT_ENVIRONMENT, so "prod" or "live" would be a guess about this team.
     */
    public static function isProduction(?string $environment): bool {
        return $environment !== null && strcasecmp($environment, 'production') === 0;
    }

    public static function devMode(bool $devMode, ?string $environment): Check {
        if (!$devMode) {
            return new Check('Dev mode', Level::Ok, 'Off.');
        }

        if (self::isProduction($environment)) {
            return new Check(
                'Dev mode',
                Level::Error,
                'Dev mode is on in production: visitors see full stack traces, and Craft runs slower.',
                'Set CRAFT_DEV_MODE=false in this server\'s .env (or `devMode` in config/general.php for production).',
            );
        }

        return new Check('Dev mode', Level::Info, 'On, in environment ' . self::name($environment) . '.');
    }

    public static function allowAdminChanges(bool $allowed, ?string $environment): Check {
        if ($allowed && self::isProduction($environment)) {
            return new Check(
                'Admin changes',
                Level::Warning,
                'Settings can be changed in production\'s control panel, so production can drift from config/project.',
                'Set CRAFT_ALLOW_ADMIN_CHANGES=false in production and make schema changes locally.',
            );
        }

        return new Check('Admin changes', Level::Ok, $allowed ? 'Allowed (not production).' : 'Disallowed.');
    }

    public static function php(?string $constraint, string $version): Check {
        $satisfied = $constraint === null ? null : PhpConstraint::satisfies($constraint, $version);

        return match ($satisfied) {
            true => new Check('PHP', Level::Ok, "PHP {$version} meets Craft's requirement ({$constraint})."),
            false => new Check(
                'PHP',
                Level::Error,
                "PHP {$version} does not meet Craft's requirement ({$constraint}).",
                'Run this site on a PHP version Craft supports.',
            ),
            null => new Check('PHP', Level::Info, "PHP {$version}. Could not determine Craft's requirement to compare it with."),
        };
    }

    /**
     * Reported, not judged: Craft's minimum database versions live in its
     * server-check package, which this plugin does not depend on.
     */
    public static function database(string $driver, ?string $version): Check {
        return new Check('Database', Level::Info, $driver . ($version === null ? '' : " {$version}") . '.');
    }

    private static function name(?string $environment): string {
        return $environment === null ? '(not set)' : "'{$environment}'";
    }
}
