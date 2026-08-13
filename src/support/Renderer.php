<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Throwable;

/**
 * Turns a tool's array payload into text a person can read.
 *
 * Tools return one of a handful of recurring shapes (see Response): a list of
 * uniform rows, a flat key-value map, a count breakdown, and nesting of those.
 * Each gets the layout that reads best (aligned table, aligned key-value block,
 * indented sub-block) and everything else degrades to pretty JSON rather than
 * throwing or silently dropping data: this view is a convenience, never the
 * only copy of the payload.
 *
 * It never decides colour itself; Palette does, and this class only asks for
 * roles. Widths are always measured on uncoloured text, so a coloured render
 * lays out exactly like a plain one.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Renderer {
    /**
     * Nesting past this reads worse indented than as JSON, and bounds the
     * recursion on payloads that nest without limit (Matrix inside Matrix).
     */
    private const int MAX_DEPTH = 5;

    private const string INDENT = '  ';

    private const string GAP = '  ';

    private const string NOTHING = '(empty)';

    /**
     * Longest a scalar list may be before it stops reading as one line.
     */
    private const int INLINE_LIMIT = 100;

    public function __construct(private Palette $palette) {
    }

    /**
     * @param array<mixed> $payload
     */
    public function render(array $payload): string {
        try {
            return $this->block($payload, 0);
        } catch (Throwable) {
            return $this->json($payload);
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function block(array $value, int $depth): string {
        if ($value === []) {
            return $this->palette->muted(self::NOTHING);
        }

        if ($depth > self::MAX_DEPTH) {
            return $this->json($value);
        }

        $table = $this->table($value);
        if ($table !== null) {
            return $table;
        }

        if (array_is_list($value)) {
            return $this->items($value, $depth);
        }

        return $this->map($value, $depth);
    }

    /**
     * A list of uniform rows, or a map of them (where the map key becomes the
     * unnamed first column), laid out as an aligned table. Null when the value
     * is not row-shaped, so the caller can fall back to a block.
     *
     * Columns are the union of the rows' keys in first-seen order: a row that
     * omits one leaves the cell blank instead of costing every other row its
     * column.
     *
     * @param array<mixed> $value
     */
    private function table(array $value): ?string {
        $rows = array_values($value);
        if (!$this->areRows($rows)) {
            return null;
        }

        /** @var array<int, array<string, scalar|null>> $rows */
        $columns = array_values(array_unique(array_merge(...array_map(array_keys(...), $rows))));
        $matrix = array_map(fn (array $row): array => $this->cells($row, $columns), $rows);

        if (!array_is_list($value)) {
            $columns = ['', ...$columns];
            $matrix = array_map(
                static fn (array $cells, int|string $key): array => [(string) $key, ...$cells],
                $matrix,
                array_keys($value),
            );
        }

        return $this->rows(array_map(strval(...), $columns), $matrix);
    }

    /**
     * Every row must be a non-empty keyed array of cell-able values; anything
     * nested or multiline would break the alignment the table exists for.
     *
     * @param array<mixed> $rows
     */
    private function areRows(array $rows): bool {
        if ($rows === []) {
            return false;
        }

        $shaped = array_filter(
            $rows,
            fn (mixed $row): bool => is_array($row) && $row !== [] && !array_is_list($row) && $this->areCells($row),
        );

        return count($shaped) === count($rows);
    }

    /**
     * @param array<mixed> $row
     */
    private function areCells(array $row): bool {
        $cells = array_filter(
            $row,
            static fn (mixed $cell): bool => ($cell === null || is_scalar($cell)) && !str_contains((string) $cell, "\n"),
        );

        return count($cells) === count($row);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string>   $columns
     *
     * @return array<int, string>
     */
    private function cells(array $row, array $columns): array {
        return array_map(
            fn (string $column): string => array_key_exists($column, $row) ? $this->scalar($row[$column]) : '',
            $columns,
        );
    }

    /**
     * @param array<int, string>              $columns
     * @param array<int, array<int, string>>  $matrix
     */
    private function rows(array $columns, array $matrix): string {
        $widths = array_map(
            static fn (string $header, int $index): int => max(
                mb_strlen($header),
                ...array_map(static fn (array $cells): int => mb_strlen($cells[$index]), $matrix),
            ),
            $columns,
            array_keys($columns),
        );

        $rule = array_map(static fn (int $width): string => str_repeat('-', $width), $widths);
        $body = array_map(fn (array $cells): string => $this->line($cells, $widths), $matrix);

        return implode("\n", [
            $this->palette->heading($this->line($columns, $widths)),
            $this->palette->muted($this->line($rule, $widths)),
            ...$body,
        ]);
    }

    /**
     * @param array<int, string> $cells
     * @param array<int, int>    $widths
     */
    private function line(array $cells, array $widths): string {
        return rtrim(implode(self::GAP, array_map($this->pad(...), $cells, $widths)));
    }

    private function pad(string $text, int $width): string {
        return $text . str_repeat(' ', max(0, $width - mb_strlen($text)));
    }

    /**
     * A keyed map as aligned `key: value` lines. Keys whose value needs its own
     * block are excluded from the alignment, so one deep branch cannot push
     * every scalar line off to the right.
     *
     * @param array<mixed> $value
     */
    private function map(array $value, int $depth): string {
        $inline = array_filter($value, $this->isInline(...));
        $width = $this->keyWidth($inline);

        $lines = array_map(
            fn (int|string $key): string => $this->entry((string) $key, $value[$key], $width, $depth),
            array_keys($value),
        );

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $inline
     */
    private function keyWidth(array $inline): int {
        if ($inline === []) {
            return 0;
        }

        return max(array_map(mb_strlen(...), array_map(strval(...), array_keys($inline))));
    }

    private function entry(string $key, mixed $value, int $width, int $depth): string {
        $label = $this->palette->key($key) . ':';

        if (!$this->isInline($value)) {
            return $label . "\n" . $this->indent($this->value($value, $depth + 1));
        }

        return $label . str_repeat(' ', $width - mb_strlen($key) + 1) . $this->inline($value);
    }

    /**
     * A list that is neither table-shaped nor short enough to inline: one
     * bulleted block per item, so multi-key items stay grouped.
     *
     * @param array<mixed> $list
     */
    private function items(array $list, int $depth): string {
        $blocks = array_map(
            fn (mixed $item): string => $this->bullet($this->value($item, $depth + 1)),
            $list,
        );

        return implode("\n", $blocks);
    }

    private function bullet(string $text): string {
        $lines = explode("\n", $text);
        $first = array_shift($lines);
        $rest = array_map(static fn (string $line): string => self::INDENT . $line, $lines);

        return implode("\n", ['- ' . $first, ...$rest]);
    }

    private function value(mixed $value, int $depth): string {
        if (is_array($value)) {
            return $this->block($value, $depth);
        }

        return $this->scalar($value);
    }

    /**
     * Whether a value fits on the same line as its key. Anything that is not
     * an array renders to a single line unless it is a multiline string, which
     * reads better as an indented block.
     */
    private function isInline(mixed $value): bool {
        if (is_array($value)) {
            return $value === [] || $this->isInlineList($value);
        }

        return !str_contains($this->scalar($value), "\n");
    }

    /**
     * @param array<mixed> $value
     */
    private function isInlineList(array $value): bool {
        if (!array_is_list($value)) {
            return false;
        }

        $scalars = array_filter(
            $value,
            static fn (mixed $item): bool => ($item === null || is_scalar($item))
                && !str_contains((string) $item, "\n")
                && !str_contains((string) $item, ','),
        );

        return count($scalars) === count($value) && mb_strlen($this->join($value)) <= self::INLINE_LIMIT;
    }

    private function inline(mixed $value): string {
        if ($value === []) {
            return $this->palette->muted(self::NOTHING);
        }

        if (is_array($value)) {
            return $this->join($value);
        }

        return $this->scalar($value);
    }

    /**
     * @param array<mixed> $value
     */
    private function join(array $value): string {
        return implode(', ', array_map($this->scalar(...), $value));
    }

    /**
     * JSON scalar semantics, kept legible: an agent reading this must not have
     * to guess whether a field was absent, null, or an empty string.
     */
    private function scalar(mixed $value): string {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === '') {
            return '""';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $this->json($value, false);
    }

    private function indent(string $text): string {
        $lines = array_map(
            static fn (string $line): string => $line === '' ? $line : self::INDENT . $line,
            explode("\n", $text),
        );

        return implode("\n", $lines);
    }

    /**
     * Last resort for anything the layouts above cannot represent. Partial
     * output is deliberate: a value PHP cannot encode must not cost the caller
     * the rest of the payload.
     */
    private function json(mixed $value, bool $pretty = true): string {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $encoded = json_encode($value, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);

        return $encoded !== false ? $encoded : get_debug_type($value);
    }
}
