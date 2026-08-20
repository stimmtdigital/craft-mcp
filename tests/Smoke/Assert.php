<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * Value level checks on a tool payload.
 *
 * WHY, given the harness already diffs shape: shape is blind to emptiness. A
 * list_entries that starts returning zero entries has exactly the shape it had
 * yesterday, and a snapshot diff stays silent about it. These rules assert the
 * few things about a payload that must be true regardless of the data, so the
 * quiet failures are loud.
 *
 * Rules are written as `path => expectation`:
 *
 *   'success'   => true          strict equality
 *   'count'     => '>=1'         numeric floor
 *   'entries'   => 'notEmpty'    a list or string with something in it
 *   'id'        => 'isInt'       type
 *   'warnings'  => 'present'     the key exists, whatever it holds
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Assert {
    private const string MISSING = "\0missing";

    /**
     * @param array<string, mixed> $rules
     * @return list<string> one line per broken rule, empty when all hold
     */
    public static function check(mixed $payload, array $rules): array {
        $failures = [];
        foreach ($rules as $path => $expectation) {
            $failure = self::rule($payload, (string) $path, $expectation);
            if ($failure !== null) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    private static function rule(mixed $payload, string $path, mixed $expectation): ?string {
        $value = self::pluck($payload, $path);

        if ($value === self::MISSING) {
            return "{$path} is missing";
        }

        if (!is_string($expectation)) {
            return $value === $expectation
                ? null
                : "{$path} expected " . self::render($expectation) . ', got ' . self::render($value);
        }

        return self::keyword($path, $value, $expectation);
    }

    private static function keyword(string $path, mixed $value, string $expectation): ?string {
        if (preg_match('/^>=(\d+)$/', $expectation, $matches) === 1) {
            $floor = (int) $matches[1];
            $actual = is_array($value) ? count($value) : $value;

            return is_numeric($actual) && $actual >= $floor
                ? null
                : "{$path} expected at least {$floor}, got " . self::render($value);
        }

        return match ($expectation) {
            'present' => null,
            'notEmpty' => self::notEmpty($value) ? null : "{$path} is empty",
            'isInt' => is_int($value) ? null : "{$path} is not an integer, got " . self::render($value),
            'isString' => is_string($value) ? null : "{$path} is not a string, got " . self::render($value),
            'isArray' => is_array($value) ? null : "{$path} is not an array, got " . self::render($value),
            default => $value === $expectation
                ? null
                : "{$path} expected " . self::render($expectation) . ', got ' . self::render($value),
        };
    }

    private static function notEmpty(mixed $value): bool {
        if (is_array($value)) {
            return $value !== [];
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return $value !== null;
    }

    private static function pluck(mixed $value, string $path): mixed {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value)) {
                return self::MISSING;
            }

            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!array_key_exists($key, $value)) {
                return self::MISSING;
            }

            $value = $value[$key];
        }

        return $value;
    }

    private static function render(mixed $value): string {
        if (is_array($value)) {
            return 'an array of ' . count($value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : 'unknown';
    }
}
