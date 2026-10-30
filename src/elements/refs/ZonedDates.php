<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use craft\base\FieldInterface;
use craft\fields\Date;
use DateTimeInterface;
use DateTimeZone;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\InvalidInput;
use stimmt\craft\Mcp\elements\Warning;

/**
 * Date fields with "Show time zone" on, read and written as
 * {"date": "Y-m-d H:i:s", "timezone": "<IANA name>"} (#75).
 *
 * WHY: such a field stores a zone name next to its instant, and an ISO 8601
 * string can only carry an offset, which several zones share with different
 * daylight saving rules. get_entry returned that offset string and Craft
 * stored it in the system zone, so writing back exactly what was read moved
 * the event. {date, timezone} is Craft's own form-input shape: it reads the
 * date in that zone and stores both, so the read shape writes back unchanged.
 *
 * Date fields without a zone keep the ISO string; nothing about them changes.
 */
final readonly class ZonedDates implements FieldTranslator {
    private const string FORMAT = 'Y-m-d H:i:s';

    public function handles(FieldInterface $field): bool {
        return $field instanceof Date && $field->showTimeZone;
    }

    /**
     * The read shape, from the DateTime Craft normalised: it carries the
     * stored zone, which serializeValue() would flatten to an offset.
     *
     * @return array{date: string, timezone: string}
     */
    public static function read(DateTimeInterface $value): array {
        return ['date' => $value->format(self::FORMAT), 'timezone' => $value->getTimezone()->getName()];
    }

    public function toKeys(FieldInterface $field, mixed $value, Context $context): mixed {
        return $value;
    }

    public function toIds(FieldInterface $field, mixed $value, Context $context): mixed {
        if (is_array($value) && is_string($value['timezone'] ?? null)) {
            if (!in_array($value['timezone'], DateTimeZone::listIdentifiers(), true)) {
                throw new InvalidInput("Field '{$field->handle}': '{$value['timezone']}' is not a time zone name. Use an IANA name such as Europe/Amsterdam.");
            }

            return $value;
        }

        if (is_string($value) && $value !== '') {
            $context->warn(new Warning(
                (string) $field->handle,
                (string) $field->handle,
                [],
                'This field keeps a time zone, and a date-time string cannot carry one, so it was stored in the system time zone. Send {"date": "YYYY-MM-DD HH:MM:SS", "timezone": "<zone name>"} to keep it.',
            ));
        }

        return $value;
    }
}
