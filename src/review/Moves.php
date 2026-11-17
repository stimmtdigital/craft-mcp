<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * Which blocks a reorder actually moved.
 *
 * WHY not "every block whose position number changed": adding one block at the
 * top shifts the number of every block below it, and moving one block from
 * last to first does the same. Both would report the whole field as moved,
 * which hides the one block an editor dragged. The blocks that stayed put are
 * the longest run that kept its relative order (a longest common subsequence),
 * and only the blocks outside that run moved. That is the smallest set of
 * moves that explains the new order, which is what a person reading the diff
 * would say happened.
 */
final class Moves {
    /**
     * @param list<string> $before block keys present on both sides, in their old order
     * @param list<string> $after the same keys, in their new order
     * @return list<string> the keys that moved, in their new order
     */
    public static function between(array $before, array $after): array {
        $kept = array_flip(self::longestKeptRun($before, $after));

        return array_values(array_filter($after, static fn (string $key): bool => !isset($kept[$key])));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<string>
     */
    private static function longestKeptRun(array $before, array $after): array {
        $table = self::table($before, $after);
        $kept = [];
        $i = 0;
        $j = 0;

        while ($i < count($before) && $j < count($after)) {
            if ($before[$i] === $after[$j]) {
                $kept[] = $before[$i];
                $i++;
                $j++;

                continue;
            }

            $table[$i + 1][$j] >= $table[$i][$j + 1] ? $i++ : $j++;
        }

        return $kept;
    }

    /**
     * The suffix-length table: $table[$i][$j] is the length of the longest
     * common run of $before from $i on and $after from $j on.
     *
     * @param list<string> $before
     * @param list<string> $after
     * @return array<int, array<int, int>>
     */
    private static function table(array $before, array $after): array {
        $table = [count($before) => array_fill(0, count($after) + 1, 0)];

        for ($i = count($before) - 1; $i >= 0; $i--) {
            $table[$i] = self::row($before[$i], $after, $table[$i + 1]);
        }

        return $table;
    }

    /**
     * @param list<string> $after
     * @param array<int, int> $next the row below this one
     * @return array<int, int>
     */
    private static function row(string $key, array $after, array $next): array {
        $row = array_fill(0, count($after) + 1, 0);

        for ($j = count($after) - 1; $j >= 0; $j--) {
            $row[$j] = $key === $after[$j] ? $next[$j + 1] + 1 : max($next[$j], $row[$j + 1]);
        }

        return $row;
    }
}
