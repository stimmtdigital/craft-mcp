<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * Snapshot comparison, the human readable summary, and the one assertion that
 * needs more than a single profile to make.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Report {
    /**
     * @param array<string, mixed> $snapshot
     */
    public static function summary(array $snapshot): string {
        $steps = is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];
        $counts = [];
        foreach ($steps as $step) {
            $status = is_array($step) ? (string) ($step['status'] ?? 'unknown') : 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);
        $parts = [];
        foreach ($counts as $status => $count) {
            $parts[] = "{$status}={$count}";
        }

        $summary = 'tools=' . ($snapshot['toolCount'] ?? 0) . ' steps: ' . implode(' ', $parts) . "\n";

        return $summary . self::uncoveredLine($snapshot) . self::scopeLines($snapshot) . self::stepLines($steps);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function uncoveredLine(array $snapshot): string {
        $uncovered = is_array($snapshot['uncovered'] ?? null) ? $snapshot['uncovered'] : [];

        return $uncovered === []
            ? ''
            : 'uncovered tools (' . count($uncovered) . '): ' . implode(', ', $uncovered) . "\n";
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private static function scopeLines(array $snapshot): string {
        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : null;
        if ($scope === null) {
            return '';
        }

        $outside = is_array($scope['outside'] ?? null) ? $scope['outside'] : [];
        $lines = 'scope ' . ($scope['granted'] ?? '?') . ': ' . count($outside) . " tools probed outside it\n";

        foreach (is_array($scope['violations'] ?? null) ? $scope['violations'] : [] as $violation) {
            $lines .= "  FAIL {$violation}\n";
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $steps
     */
    private static function stepLines(array $steps): string {
        $lines = '';
        foreach ($steps as $name => $step) {
            if (!is_array($step) || in_array($step['status'] ?? '', ['ok', 'skipped'], true)) {
                continue;
            }

            $label = array_key_exists('unexpected', $step) ? 'FAIL' : 'known';
            $detail = $step['unexpected'] ?? $step['message'] ?? $step['error'] ?? '';
            $failures = is_array($step['failures'] ?? null) ? ' ' . implode('; ', $step['failures']) : '';
            $lines .= "  {$label} {$name} [{$step['status']}] {$detail}{$failures}\n";
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function failureCount(array $snapshot): int {
        $steps = is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];
        $failures = 0;
        foreach ($steps as $step) {
            if (is_array($step) && array_key_exists('unexpected', $step)) {
                $failures++;
            }
        }

        $scope = is_array($snapshot['scope'] ?? null) ? $snapshot['scope'] : [];
        $violations = is_array($scope['violations'] ?? null) ? $scope['violations'] : [];

        return $failures + count($violations);
    }

    /**
     * The one thing no single profile can show: a narrower scope must reach
     * strictly fewer tools than a wider one. Pinning each count in its own
     * baseline catches a count that moves; this catches the filter collapsing
     * so that every scope sees everything, which each baseline would happily
     * re-record as the new normal.
     *
     * @param array<string, array<string, mixed>> $snapshots
     * @return list<string>
     */
    public static function ordering(array $snapshots): array {
        $counts = [];
        foreach (['http-readonly', 'http-content', 'http-full'] as $profile) {
            $count = $snapshots[$profile]['toolCount'] ?? null;
            if (is_int($count)) {
                $counts[$profile] = $count;
            }
        }

        $violations = [];
        $names = array_keys($counts);
        for ($i = 0; $i < count($names) - 1; $i++) {
            [$narrow, $wide] = [$names[$i], $names[$i + 1]];
            if ($counts[$narrow] >= $counts[$wide]) {
                $violations[] = "{$narrow} advertises {$counts[$narrow]} tools, {$wide} advertises {$counts[$wide]}: a narrower scope must reach strictly fewer";
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function diff(array $baseline, array $current): array {
        $differences = [];
        self::walk($baseline, $current, '', $differences);
        sort($differences);

        return $differences;
    }

    /**
     * @param list<string> $differences
     */
    private static function walk(mixed $baseline, mixed $current, string $path, array &$differences): void {
        if ($baseline === $current) {
            return;
        }

        if (!is_array($baseline) || !is_array($current)) {
            $differences[] = $path . ': ' . self::render($baseline) . ' -> ' . self::render($current);

            return;
        }

        foreach (array_keys($baseline + $current) as $key) {
            $child = $path === '' ? (string) $key : $path . '.' . $key;

            if (!array_key_exists($key, $current)) {
                $differences[] = $child . ': removed';

                continue;
            }

            if (!array_key_exists($key, $baseline)) {
                $differences[] = $child . ': added';

                continue;
            }

            self::walk($baseline[$key], $current[$key], $child, $differences);
        }
    }

    private static function render(mixed $value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $encoded = is_string($encoded) ? $encoded : 'unknown';

        return strlen($encoded) > 120 ? substr($encoded, 0, 117) . '...' : $encoded;
    }
}
