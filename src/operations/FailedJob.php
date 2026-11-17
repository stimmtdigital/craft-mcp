<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One failed queue job as a caller may see it.
 *
 * WHY built from named columns rather than the row: the queue row carries the
 * serialized job, whose properties can hold anything a plugin pushed (an API
 * payload, a token, a customer's address). Only what is listed here leaves.
 */
final class FailedJob {
    /** Craft already truncates the stored error; this caps what one row can add to a page. */
    public const int ERROR_LENGTH = 1000;

    /**
     * @param array<string, mixed> $row A queue table row: id, description, timePushed, attempt, dateFailed, error
     * @param string|null $class The job's class, null when it could not be read
     * @return array{id: int, class: string|null, description: string|null, attempt: int|null, timePushed: string|null, failedAt: string|null, error: string|null}
     */
    public static function present(array $row, ?string $class): array {
        return [
            'id' => (int) $row['id'],
            'class' => $class,
            'description' => self::text($row['description'] ?? null),
            'attempt' => isset($row['attempt']) ? (int) $row['attempt'] : null,
            'timePushed' => self::fromUnix($row['timePushed'] ?? null),
            'failedAt' => self::fromDb($row['dateFailed'] ?? null),
            'error' => self::error($row['error'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function error(mixed $value): ?string {
        $error = self::text($value);

        return $error === null ? null : mb_strimwidth($error, 0, self::ERROR_LENGTH, '…');
    }

    private static function fromUnix(mixed $value): ?string {
        return is_numeric($value) ? (new DateTimeImmutable('@' . (int) $value))->format(DateTimeInterface::ATOM) : null;
    }

    /**
     * Craft stores datetime columns in UTC.
     */
    private static function fromDb(mixed $value): ?string {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $moment === false ? null : $moment->format(DateTimeInterface::ATOM);
    }
}
