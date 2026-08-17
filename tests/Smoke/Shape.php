<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * Reduces a tool payload to its shape: the keys it carries and the type of each
 * leaf.
 *
 * WHY shape rather than the values: the harness exists to guard refactors whose
 * whole promise is that structure changes and behaviour does not. Diffing values
 * would drown that signal in noise, because ids, timestamps and counts move on
 * every run for reasons nobody cares about. Diffing shape catches the failures
 * that matter (a key that disappears, a list that becomes an object, a number
 * that becomes a string) and stays quiet otherwise.
 *
 * Booleans and nulls survive verbatim, because they are low cardinality and
 * carry meaning: a `success` flipping from true to false is exactly the
 * regression this is here to catch, and reducing it to a type token would hide
 * it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Shape {
    /**
     * Lists are reduced to one merged element shape. A run that returns three
     * entries and a run that returns four are the same shape, which is the
     * point; a run where one entry suddenly lacks a field is not, and the merge
     * marks that field optional so it still shows up in the diff.
     */
    public static function of(mixed $value): mixed {
        if (is_bool($value) || $value === null) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return '<number>';
        }

        if (is_string($value)) {
            return '<string>';
        }

        if (!is_array($value)) {
            return '<' . gettype($value) . '>';
        }

        if (array_is_list($value)) {
            return self::ofList($value);
        }

        // A map keyed by element id (Matrix blocks, most notably) carries fresh
        // keys on every run. Those keys are data, not shape, so the map is
        // reduced to one merged value shape the same way a list is.
        return self::isKeyed($value) ? ['<keyed>' => self::ofList(array_values($value))[0] ?? []] : self::ofObject($value);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isKeyed(array $value): bool {
        if ($value === []) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (!is_int($key) && !ctype_digit((string) $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<string, mixed>
     */
    private static function ofObject(array $value): array {
        $shape = [];
        foreach ($value as $key => $item) {
            $shape[(string) $key] = self::of($item);
        }

        ksort($shape);

        return $shape;
    }

    /**
     * @param list<mixed> $value
     * @return list<mixed>
     */
    private static function ofList(array $value): array {
        if ($value === []) {
            return [];
        }

        $merged = self::of($value[0]);
        foreach (array_slice($value, 1) as $item) {
            $merged = self::merge($merged, self::of($item));
        }

        return [$merged];
    }

    /**
     * Union of two shapes. Disagreement is reported rather than resolved, so a
     * field that is a number in one row and a string in the next is visible
     * instead of silently taking the first answer.
     */
    private static function merge(mixed $left, mixed $right): mixed {
        if ($left === $right) {
            return $left;
        }

        if (is_bool($left) && is_bool($right)) {
            // Rows of a list differing on a flag is ordinary data, not a shape
            // disagreement. Collapsing keeps the diff quiet about it.
            return '<bool>';
        }

        if (!is_array($left) || !is_array($right)) {
            return self::disagreement($left, $right);
        }

        if (array_is_list($left) && array_is_list($right)) {
            return self::mergeLists($left, $right);
        }

        if (array_is_list($left) !== array_is_list($right)) {
            return self::disagreement($left, $right);
        }

        return self::mergeObjects($left, $right);
    }

    /**
     * @param list<mixed> $left
     * @param list<mixed> $right
     * @return list<mixed>
     */
    private static function mergeLists(array $left, array $right): array {
        if ($left === []) {
            return $right;
        }

        if ($right === []) {
            return $left;
        }

        return [self::merge($left[0], $right[0])];
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     * @return array<string, mixed>
     */
    private static function mergeObjects(array $left, array $right): array {
        $merged = [];
        foreach (array_keys($left + $right) as $key) {
            $name = (string) $key;
            $inLeft = array_key_exists($key, $left);
            $inRight = array_key_exists($key, $right);

            if ($inLeft && $inRight) {
                $merged[$name] = self::merge($left[$key], $right[$key]);

                continue;
            }

            $present = $inLeft ? $left[$key] : $right[$key];
            $merged[$name . '?'] = $present;
        }

        ksort($merged);

        return $merged;
    }

    /**
     * Unions are flattened rather than nested, so three-way disagreement reads
     * as <null|number|string> instead of <<number|null>|string>.
     */
    private static function disagreement(mixed $left, mixed $right): string {
        $parts = array_unique(array_merge(self::parts($left), self::parts($right)));
        sort($parts);

        return '<' . implode('|', $parts) . '>';
    }

    /**
     * @return list<string>
     */
    private static function parts(mixed $value): array {
        $label = self::label($value);
        if (str_starts_with($label, '<') && str_ends_with($label, '>')) {
            return explode('|', substr($label, 1, -1));
        }

        return [$label];
    }

    private static function label(mixed $value): string {
        if (is_array($value)) {
            return array_is_list($value) ? 'list' : 'object';
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value);

        return is_string($encoded) ? $encoded : 'unknown';
    }
}
