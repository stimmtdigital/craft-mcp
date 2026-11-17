<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Level;

/**
 * The health checks in the order a reader acts on them, with a count per level.
 *
 * WHY sorted by severity: the question the report answers is "is this install
 * OK, and if not, what do I do", so what needs doing comes first. WHY stable
 * within a level: the checks are gathered area by area, and keeping that order
 * among equals keeps related lines together.
 */
final class HealthReport {
    private const array SEVERITY = [
        'error' => 0,
        'warning' => 1,
        'info' => 2,
        'ok' => 3,
    ];

    /**
     * @param list<HealthCheck> $checks
     * @return array{checks: list<array<string, string>>, summary: array<string, int>}
     */
    public static function build(array $checks): array {
        return [
            'checks' => array_map(static fn (HealthCheck $check): array => $check->toArray(), self::ordered($checks)),
            'summary' => self::summary($checks),
        ];
    }

    /**
     * @param list<HealthCheck> $checks
     * @return list<HealthCheck>
     */
    public static function ordered(array $checks): array {
        // usort is stable since PHP 8.0, which is what keeps equals in order.
        usort(
            $checks,
            static fn (HealthCheck $a, HealthCheck $b): int => self::SEVERITY[$a->check->level->value]
                <=> self::SEVERITY[$b->check->level->value],
        );

        return $checks;
    }

    /**
     * Every level is present, zero included, so a caller can read
     * `summary.error === 0` without guarding for a missing key.
     *
     * @param list<HealthCheck> $checks
     * @return array<string, int>
     */
    public static function summary(array $checks): array {
        $summary = array_fill_keys(array_map(static fn (Level $level): string => $level->value, Level::cases()), 0);

        foreach ($checks as $check) {
            $summary[$check->check->level->value]++;
        }

        return $summary;
    }
}
