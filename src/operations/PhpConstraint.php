<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

/**
 * Whether a PHP version satisfies a Composer version constraint, for the
 * handful of operators a `"php"` requirement is written with.
 *
 * WHY not composer/semver: it is not a dependency of this plugin, and a
 * `"php"` line is `^8.2` or `>=8.2 <8.5` in practice. A constraint spelled any
 * other way answers null, "could not tell", rather than a guess.
 */
final class PhpConstraint {
    private const string PART = '/^(\^|~|>=|<=|>|<|==?)?\s*v?(\d+(?:\.\d+){0,2})$/';

    public static function satisfies(string $constraint, string $version): ?bool {
        $alternatives = preg_split('/\s*\|\|?\s*/', trim($constraint), -1, PREG_SPLIT_NO_EMPTY);
        if ($alternatives === false || $alternatives === []) {
            return null;
        }

        $answers = array_map(static fn (string $alternative): ?bool => self::all($alternative, $version), $alternatives);
        if (in_array(null, $answers, true)) {
            return null;
        }

        return in_array(true, $answers, true);
    }

    /**
     * Every part of one alternative (space or comma separated) must hold.
     */
    private static function all(string $alternative, string $version): ?bool {
        $parts = preg_split('/[\s,]+/', trim($alternative), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return null;
        }

        $answers = array_map(static fn (string $part): ?bool => self::one($part, $version), $parts);
        if (in_array(null, $answers, true)) {
            return null;
        }

        return !in_array(false, $answers, true);
    }

    private static function one(string $part, string $version): ?bool {
        if (preg_match(self::PART, $part, $match) !== 1) {
            return null;
        }

        $bound = $match[2];

        return match ($match[1]) {
            '^', '~' => version_compare($version, $bound, '>=') && self::sameMajor($version, $bound),
            '>=' => version_compare($version, $bound, '>='),
            '<=' => version_compare($version, $bound, '<='),
            '>' => version_compare($version, $bound, '>'),
            '<' => version_compare($version, $bound, '<'),
            // An exact pin: Composer pads both sides to four parts before
            // comparing, which version_compare does not, so it is not judged.
            default => null,
        };
    }

    /**
     * `^8.2` and `~8.2` both stop below the next major. (`~8.2.1` would stop
     * below 8.3, which no `"php"` line in this ecosystem is written as.)
     */
    private static function sameMajor(string $version, string $bound): bool {
        return (int) $version === (int) $bound;
    }
}
