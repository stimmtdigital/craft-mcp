<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;

/**
 * Verdicts on migrations waiting to run and on the updates Craft knows about.
 */
final class UpdateRules {
    /**
     * @param list<string> $handles What Craft reports as pending: "craft", plugin handles, "content"
     */
    public static function migrations(array $handles): Check {
        if ($handles === []) {
            return new Check('Migrations', Level::Ok, 'None pending.');
        }

        return new Check(
            'Migrations',
            Level::Error,
            'Pending for ' . implode(', ', $handles) . '. Until they run, the control panel shows the update screen and code '
            . 'that expects the new schema fails.',
            'Run `php craft up` on this server (part of every deploy).',
        );
    }

    /**
     * The files are older than the database when someone deployed a
     * downgrade, or rolled back code without the database.
     */
    public static function schema(bool $compatible): Check {
        if ($compatible) {
            return new Check('Craft schema', Level::Ok, 'The installed files match or are newer than the database.');
        }

        return new Check(
            'Craft schema',
            Level::Error,
            'The database was migrated by a newer Craft than the one installed here.',
            'Deploy the Craft version composer.lock pins on the environment that migrated the database.',
        );
    }

    /**
     * @param array{total: int, critical: bool, names: list<string>}|null $cached Null when Craft has no cached update info
     */
    public static function available(?array $cached): Check {
        if ($cached === null) {
            // Never checked from here: asking the update server is an outbound
            // call, and a health check must not depend on, or wait for, one.
            return new Check(
                'Available updates',
                Level::Info,
                'Could not determine: Craft has no cached update info, and this check does not contact the update server.',
                'Open Utilities → Updates in the control panel to fetch it.',
            );
        }

        if ($cached['critical']) {
            return new Check(
                'Available updates',
                Level::Warning,
                'A critical update is available (' . implode(', ', $cached['names']) . ').',
                'Update with `composer update` locally, test, deploy, then run `php craft up`.',
            );
        }

        if ($cached['total'] > 0) {
            return new Check('Available updates', Level::Info, "{$cached['total']} available (" . implode(', ', $cached['names']) . ').');
        }

        return new Check('Available updates', Level::Ok, 'Up to date, as of Craft\'s last update check.');
    }
}
