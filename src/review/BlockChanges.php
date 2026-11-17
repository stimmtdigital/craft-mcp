<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * Block by block, what changed in a Matrix (or Addresses) value.
 *
 * WHY per block: a Matrix field is a page's whole body. Reported as one value
 * it is two walls of JSON that differ somewhere; reported per block it says
 * "the third block's heading changed and a quote block was added", which is
 * what a reviewer approves or rejects.
 *
 * Blocks are matched by their key, which is the block's element id. The caller
 * maps a draft's own copies of blocks back to the canonical ids first (see
 * Rekey); without that every block the draft touched would read as one block
 * removed and another added.
 *
 * Each entry reads {block, type, kind, ...}: "added" and "removed" carry the
 * block's position and its non-empty fields, "moved" carries from/to, and
 * "changed" carries the block's own changes, plus from/to when it moved too.
 */
final readonly class BlockChanges {
    public const string MOVED = 'moved';

    /** The keys a block carries besides its fields that are not content. */
    private const array NOT_ATTRIBUTES = ['fields', 'position', 'collapsed'];

    public function __construct(
        private FieldChanges $fields,
    ) {
    }

    /**
     * @param array<array-key, mixed> $before blocks keyed by block id
     * @param array<array-key, mixed> $after blocks keyed by block id
     * @return list<array<string, mixed>>
     */
    public function between(array $before, array $after): array {
        $beforeOrder = Values::blockOrder($before);
        $afterOrder = Values::blockOrder($after);
        $moved = array_flip(Moves::between(
            array_values(array_intersect($beforeOrder, $afterOrder)),
            array_values(array_intersect($afterOrder, $beforeOrder)),
        ));

        $changes = [];
        foreach ($afterOrder as $index => $key) {
            $changes[] = isset($before[$key])
                ? $this->kept($key, $before[$key], $after[$key], $this->positions($beforeOrder, $key, $index), isset($moved[$key]))
                : $this->whole($key, $after[$key], FieldChanges::ADDED, $index + 1);
        }

        foreach (array_diff($beforeOrder, $afterOrder) as $index => $key) {
            $changes[] = $this->whole($key, $before[$key], FieldChanges::REMOVED, $index + 1);
        }

        return array_values(array_filter($changes));
    }

    /**
     * A block present on both sides: changed, moved, both, or neither.
     *
     * @param array{from: int, to: int} $positions
     * @return array<string, mixed>|null null when the block is untouched
     */
    private function kept(string $key, array $before, array $after, array $positions, bool $moved): ?array {
        $changes = [
            ...$this->fields->between($this->attributes($before), $this->attributes($after))['changes'],
            ...$this->fields->between($before['fields'], $after['fields'])['changes'],
        ];

        if ($changes !== []) {
            return [...$this->head($key, $after, FieldChanges::CHANGED), ...($moved ? $positions : []), 'changes' => $changes];
        }

        return $moved ? [...$this->head($key, $after, self::MOVED), ...$positions] : null;
    }

    /**
     * A block only one side has, listed with the fields it holds so the
     * reader sees what arrived or went, not just that something did.
     *
     * @return array<string, mixed>
     */
    private function whole(string $key, array $block, string $kind, int $position): array {
        $fields = $kind === FieldChanges::ADDED
            ? $this->fields->between([], $block['fields'])
            : $this->fields->between($block['fields'], []);

        return [...$this->head($key, $block, $kind), 'position' => $position, 'changes' => $fields['changes']];
    }

    /**
     * @return array{block: string, type: mixed, kind: string}
     */
    private function head(string $key, array $block, string $kind): array {
        return ['block' => $key, 'type' => $block['type'] ?? null, 'kind' => $kind];
    }

    /**
     * @param list<string> $beforeOrder
     * @return array{from: int, to: int}
     */
    private function positions(array $beforeOrder, string $key, int $afterIndex): array {
        return ['from' => (int) array_search($key, $beforeOrder, true) + 1, 'to' => $afterIndex + 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $block): array {
        return array_diff_key($block, array_flip(self::NOT_ATTRIBUTES));
    }
}
