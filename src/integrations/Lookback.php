<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Mcp\Exception\ToolCallException;

/**
 * The `days` window the plugin-integration reads take, and the dates they
 * answer with.
 *
 * WHY UTC in both directions: Craft and the plugins write timestamps through
 * Db::prepareDateForDb(), which stores UTC without a zone. Comparing against
 * a local "now", or echoing the stored string as is, would shift every date
 * by the site's offset without saying so.
 */
final class Lookback {
    public const int MAX_DAYS = 3650;

    public static function since(int $days, DateTimeImmutable $now): DateTimeImmutable {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new ToolCallException('days must be between 1 and ' . self::MAX_DAYS . ", got {$days}.");
        }

        return $now->setTimezone(new DateTimeZone('UTC'))->sub(new DateInterval("P{$days}D"));
    }

    /** A stored UTC timestamp as ISO 8601, so the zone travels with the value. */
    public static function utc(?string $stored): ?string {
        if ($stored === null || $stored === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stored, new DateTimeZone('UTC'));

        return $date === false ? null : $date->format(DATE_ATOM);
    }
}
