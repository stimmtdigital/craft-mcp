<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * Matches installed package versions against Packagist's advisories.
 *
 * WHY composer/semver: affectedVersions is a Composer constraint string
 * (">=1.0,<1.2.3|>=2.0,<2.0.1"), and Composer's own parser is the only reader
 * that agrees with Composer about what it means. It is the matcher
 * `composer audit` uses, the same way: the installed version as an exact
 * constraint, intersected with the advisory's range.
 */
final class Advisories {
    /**
     * Whether $constraint covers $installed. Null when either side cannot be
     * parsed, which is not the same answer as "not affected" and must not be
     * reported as one.
     */
    public static function affects(string $installed, string $constraint): ?bool {
        $parser = new VersionParser();

        try {
            $version = new Constraint('==', $parser->normalize($installed));

            return $parser->parseConstraints($constraint)->matches($version);
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $installed Package name => installed version
     * @param array<string, mixed> $advisories Packagist's `advisories` object: package name => list of advisories
     * @return array{advisories: list<array<string, mixed>>, undecided: list<array{package: string, installedVersion: string, advisoryId: string|null, affectedVersions: string}>, uncovered: list<string>}
     */
    public static function match(array $installed, array $advisories): array {
        $matched = [];
        $undecided = [];

        foreach ($installed as $package => $version) {
            $verdicts = self::verdicts($package, $version, self::listFor($advisories, $package));
            array_push($matched, ...$verdicts['matched']);
            array_push($undecided, ...$verdicts['undecided']);
        }

        return [
            'advisories' => $matched,
            'undecided' => $undecided,
            // Packagist leaves out a name it has no data on (a private package,
            // a fork), so absence means "not checked", not "safe".
            'uncovered' => array_values(array_diff(array_keys($installed), array_keys($advisories))),
        ];
    }

    /**
     * One package's advisories, split by whether they cover its version.
     * Advisories that do not cover it are dropped: that is the ordinary case.
     *
     * @param list<array<string, mixed>> $advisories
     * @return array{matched: list<array<string, mixed>>, undecided: list<array{package: string, installedVersion: string, advisoryId: string|null, affectedVersions: string}>}
     */
    private static function verdicts(string $package, string $version, array $advisories): array {
        $verdicts = ['matched' => [], 'undecided' => []];

        foreach ($advisories as $advisory) {
            $range = is_string($advisory['affectedVersions'] ?? null) ? $advisory['affectedVersions'] : '';
            $affects = self::affects($version, $range);

            if ($affects === true) {
                $verdicts['matched'][] = self::shape($package, $version, $advisory);
            }

            if ($affects === null) {
                $verdicts['undecided'][] = [
                    'package' => $package,
                    'installedVersion' => $version,
                    'advisoryId' => self::text($advisory['advisoryId'] ?? null),
                    'affectedVersions' => $range,
                ];
            }
        }

        return $verdicts;
    }

    /**
     * @param array<string, mixed> $advisories
     * @return list<array<string, mixed>>
     */
    private static function listFor(array $advisories, string $package): array {
        $list = $advisories[$package] ?? [];

        return is_array($list) ? array_values(array_filter($list, is_array(...))) : [];
    }

    /**
     * Named fields only: the advisory's deprecated and repository-internal
     * keys (remoteId, source, composerRepository) say nothing a reader acts on.
     *
     * @param array<string, mixed> $advisory
     * @return array<string, mixed>
     */
    private static function shape(string $package, string $version, array $advisory): array {
        return [
            'package' => $package,
            'installedVersion' => $version,
            'advisoryId' => self::text($advisory['advisoryId'] ?? null),
            'cve' => self::text($advisory['cve'] ?? null),
            'title' => self::text($advisory['title'] ?? null),
            'link' => self::text($advisory['link'] ?? null),
            'severity' => self::text($advisory['severity'] ?? null),
            'affectedVersions' => self::text($advisory['affectedVersions'] ?? null),
            'reportedAt' => self::text($advisory['reportedAt'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
