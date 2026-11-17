<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;

/**
 * Verdicts on the directories Craft writes to, and the disk under them.
 */
final class StorageRules {
    /** Below this a busy site fills the rest with logs, backups and transforms within days. */
    public const int WARN_BELOW_BYTES = 1024 * 1024 * 1024;

    /** Below this a database backup or an upload is likely to fail now. */
    public const int ERROR_BELOW_BYTES = 200 * 1024 * 1024;

    private const string FIX = 'Free space: old database backups in storage/backups, rotated logs in storage/logs, '
        . 'or grow the volume.';

    public static function writable(string $label, string $path, bool $writable): Check {
        if ($writable) {
            return new Check($label, Level::Ok, 'Writable.');
        }

        return new Check(
            $label,
            Level::Error,
            "{$path} is not writable by PHP, so Craft cannot write logs, caches or compiled templates there.",
            'Give the web server\'s user write access to that directory.',
        );
    }

    /**
     * @param float|false $bytes What disk_free_space() answered
     */
    public static function freeSpace(float|false $bytes): Check {
        if ($bytes === false) {
            return new Check('Free disk space', Level::Info, 'Could not determine the free space on the storage volume.');
        }

        $free = self::size($bytes);
        if ($bytes < self::ERROR_BELOW_BYTES) {
            return new Check('Free disk space', Level::Error, "{$free} free on the storage volume.", self::FIX);
        }

        if ($bytes < self::WARN_BELOW_BYTES) {
            return new Check('Free disk space', Level::Warning, "{$free} free on the storage volume.", self::FIX);
        }

        return new Check('Free disk space', Level::Ok, "{$free} free on the storage volume.");
    }

    private static function size(float $bytes): string {
        return $bytes >= 1024 ** 3
            ? round($bytes / 1024 ** 3, 1) . ' GB'
            : round($bytes / 1024 ** 2) . ' MB';
    }
}
