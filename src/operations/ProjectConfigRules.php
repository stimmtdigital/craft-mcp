<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;

/**
 * Verdicts on config/project: whether its YAML can be applied, and whether it
 * is waiting to be.
 */
final class ProjectConfigRules {
    private const string APPLY_FIX = 'Commit config/project after updating, then run `php craft project-config/apply`.';

    /**
     * WHY one check naming every mismatch: Craft refuses the whole apply while
     * any one of them stands, so the reader needs the full list to fix it in
     * one pass. This exact state broke a real install: config/project was
     * written by newer plugin versions than were deployed, and nothing applied.
     *
     * @param list<array{cause: string, existing: string, incoming: string}> $issues As Craft reports them
     */
    public static function schema(array $issues): Check {
        if ($issues === []) {
            return new Check('Config schema', Level::Ok, 'config/project matches the installed Craft and plugin versions.');
        }

        $mismatches = array_map(
            static fn (array $issue): string => "{$issue['cause']} (installed {$issue['existing']}, config/project {$issue['incoming']})",
            $issues,
        );

        return new Check(
            'Config schema',
            Level::Error,
            'config/project cannot be applied: its schema versions differ from what is installed for '
            . implode('; ', $mismatches) . '.',
            self::APPLY_FIX,
        );
    }

    /**
     * @param int|null $pending Pending items, or null when there is no config/project to compare
     */
    public static function pending(?int $pending): Check {
        if ($pending === null) {
            return new Check('Pending YAML changes', Level::Info, 'No config/project files on this server, so there is nothing to apply.');
        }

        if ($pending === 0) {
            return new Check('Pending YAML changes', Level::Ok, 'None: the database matches config/project.');
        }

        return new Check(
            'Pending YAML changes',
            Level::Warning,
            "{$pending} item(s) in config/project are not applied, so this install runs on settings other than the committed ones.",
            'Review them with Craft\'s project_config_diff, then run `php craft project-config/apply` (or project_config_apply).',
        );
    }
}
