<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\insight;

use JsonException;
use Mcp\Exception\ToolCallException;

/**
 * The packages a project actually installed, read off its composer.lock.
 *
 * WHY the lock rather than composer.json: an advisory applies to a version,
 * and composer.json only holds the constraint the version was picked from.
 * The lock is the one file that says what is on disk.
 */
final class ComposerLock {
    /**
     * @return array<string, string> Package name => installed version, sorted by name
     */
    public static function packages(string $json, bool $includeDev): array {
        $lock = self::decode($json);
        $sections = $includeDev ? ['packages', 'packages-dev'] : ['packages'];

        $installed = [];
        foreach ($sections as $section) {
            $installed += self::section($lock[$section] ?? []);
        }
        ksort($installed);

        return $installed;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array {
        try {
            $lock = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ToolCallException(
                "composer.lock is not valid JSON ({$exception->getMessage()}). Run composer install to rewrite it.",
                $exception->getCode(),
                $exception,
            );
        }

        if (!is_array($lock)) {
            throw new ToolCallException(
                'composer.lock does not hold a JSON object. Run composer install to rewrite it.',
            );
        }

        return $lock;
    }

    /**
     * A row without a name or a version cannot be matched against anything,
     * so it is left out rather than guessed at.
     *
     * @return array<string, string>
     */
    private static function section(mixed $packages): array {
        if (!is_array($packages)) {
            return [];
        }

        $installed = [];
        foreach ($packages as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
                $installed[$package['name']] = $package['version'];
            }
        }

        return $installed;
    }
}
