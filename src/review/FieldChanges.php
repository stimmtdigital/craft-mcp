<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * Field by field, what changed between two Reader payload field maps.
 *
 * WHY it works on payloads rather than on elements: the Reader already turns
 * every field into the natural-key shape an agent reads and writes (relations
 * as section and slug, Matrix as keyed blocks, zoned dates), so two payloads
 * compare the way the agent will see them, and the logic runs on plain arrays
 * that a test can build by hand.
 *
 * A change reads {field, label, kind, before, after}. A nested container is
 * not one blob: a Matrix or Addresses value reports per block under "blocks",
 * and a ContentBlock value reports its own fields under "changes".
 */
final readonly class FieldChanges {
    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    public const string CHANGED = 'changed';

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string> $labels display names by handle; their order leads the output order
     * @return array{changes: list<array<string, mixed>>, unchangedCount: int}
     */
    public function between(array $before, array $after, array $labels = []): array {
        $changes = [];
        $unchanged = 0;

        foreach ($this->handles($before, $after, $labels) as $handle) {
            $change = $this->change($handle, $before[$handle] ?? null, $after[$handle] ?? null, $labels[$handle] ?? $handle);
            if ($change === null) {
                $unchanged++;

                continue;
            }

            $changes[] = $change;
        }

        return ['changes' => $changes, 'unchangedCount' => $unchanged];
    }

    /**
     * @return array<string, mixed>|null null when the two values say the same thing
     */
    private function change(string $handle, mixed $before, mixed $after, string $label): ?array {
        if (Values::same($before, $after)) {
            return null;
        }

        $change = ['field' => $handle, 'label' => $label, 'kind' => $this->kind($before, $after)];

        if (Values::isBlockList($before) || Values::isBlockList($after)) {
            return [...$change, 'blocks' => (new BlockChanges($this))->between($this->blocks($before), $this->blocks($after))];
        }

        if (Values::isBlock($before) || Values::isBlock($after)) {
            return [...$change, 'changes' => $this->between($this->fieldsOf($before), $this->fieldsOf($after))['changes']];
        }

        return [...$change, ...$this->sides($before, $after)];
    }

    private function kind(mixed $before, mixed $after): string {
        if (Values::isEmpty($before)) {
            return self::ADDED;
        }

        return Values::isEmpty($after) ? self::REMOVED : self::CHANGED;
    }

    /**
     * Before and after, each shortened when long, with ONE flag for the pair:
     * the reader needs to know the values shown are not the whole values, not
     * which of the two was cut.
     *
     * @return array<string, mixed>
     */
    private function sides(mixed $before, mixed $after): array {
        $clippedBefore = Clip::of($before);
        $clippedAfter = Clip::of($after);
        $sides = ['before' => $clippedBefore['value'], 'after' => $clippedAfter['value']];

        if ($clippedBefore['truncated'] || $clippedAfter['truncated']) {
            $sides['truncated'] = true;
        }

        return $sides;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<string, string> $labels
     * @return list<string>
     */
    private function handles(array $before, array $after, array $labels): array {
        $handles = [...array_keys($labels), ...array_keys($before), ...array_keys($after)];

        return array_values(array_unique(array_map(strval(...), $handles)));
    }

    /**
     * @return array<string, mixed>
     */
    private function blocks(mixed $value): array {
        return Values::isBlockList($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldsOf(mixed $value): array {
        return Values::isBlock($value) ? $value['fields'] : [];
    }
}
