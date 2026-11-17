<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\report;

/**
 * Counts content changes for a maintenance report: saves and first versions
 * per section and per editor.
 *
 * Each save of an entry creates a revision, so revisions in the period are
 * the saves in the period; revision 1 is the entry's first version. Plain
 * rows in, plain totals out, so the counting is testable without Craft.
 */
final class ContentChanges {
    /**
     * @param list<array{sectionId: int|null, creatorId: int|null, num: int}> $rows
     * @param array<int, string> $sections section id => handle
     * @param array<int, string> $editors user id => username
     * @return array{saves: int, created: int, bySection: list<array{section: string, saves: int, created: int}>, byEditor: list<array{editor: string, saves: int}>}
     */
    public static function aggregate(array $rows, array $sections, array $editors): array {
        $bySection = [];
        $byEditor = [];
        $created = 0;

        foreach ($rows as $row) {
            $section = $sections[$row['sectionId'] ?? 0] ?? '(unknown)';
            // Craft records no creator for saves made by console scripts and
            // migrations; they are still changes, so they get a name of their own.
            $editor = $row['creatorId'] === null ? '(system)' : ($editors[$row['creatorId']] ?? '(deleted user)');
            $isNew = $row['num'] === 1;

            $bySection[$section] ??= ['section' => $section, 'saves' => 0, 'created' => 0];
            $bySection[$section]['saves']++;
            $bySection[$section]['created'] += $isNew ? 1 : 0;
            $byEditor[$editor] ??= ['editor' => $editor, 'saves' => 0];
            $byEditor[$editor]['saves']++;
            $created += $isNew ? 1 : 0;
        }

        return [
            'saves' => count($rows),
            'created' => $created,
            'bySection' => self::sorted(array_values($bySection), 'section'),
            'byEditor' => self::sorted(array_values($byEditor), 'editor'),
        ];
    }

    /**
     * Most saves first; ties by name, so two runs over the same data agree.
     *
     * @template T of array{saves: int}
     * @param list<T> $rows
     * @return list<T>
     */
    private static function sorted(array $rows, string $nameKey): array {
        usort($rows, static fn (array $a, array $b): int => [$b['saves'], $a[$nameKey]] <=> [$a['saves'], $b[$nameKey]]);

        return $rows;
    }
}
