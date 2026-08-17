<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\helpers\DateTimeHelper;
use DateTime;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\elements\WriteMode;
use stimmt\craft\Mcp\Mcp;

/**
 * Shared parsing for the write-tool parameters every entry write accepts:
 * the JSON fields payload and the draft/live mode. One home instead of a
 * per-tool-class copy, so a message or default changed here changes
 * everywhere.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class WriteParams {
    /**
     * @return array<array-key, mixed>
     */
    public static function fieldsPayload(?string $fields): array {
        if ($fields === null) {
            return [];
        }

        $decoded = json_decode($fields, true);
        if (!is_array($decoded)) {
            throw new ToolCallException('Invalid JSON in fields parameter');
        }

        return $decoded;
    }

    /**
     * The two schedule attributes, parsed and omitted when not supplied.
     *
     * They are the only meta attributes `describe_entry_schema` advertises that
     * are not already a named argument, so leaving them unwritable made the
     * schema tool lie about the payload it documents. A bad date fails here with
     * the value in the message rather than reaching Craft's validator, where it
     * surfaces as a field error on a key the caller did not send.
     *
     * @return array<string, DateTime>
     */
    public static function schedule(?string $postDate, ?string $expiryDate): array {
        return array_filter([
            'postDate' => self::date('postDate', $postDate),
            'expiryDate' => self::date('expiryDate', $expiryDate),
        ], static fn (?DateTime $value): bool => $value instanceof DateTime);
    }

    public static function mode(?string $mode): WriteMode {
        if ($mode !== null) {
            return WriteMode::tryFrom(strtolower($mode))
                ?? throw new ToolCallException("Unknown mode '{$mode}'; use draft or live");
        }

        return WriteMode::fromSetting(Mcp::settings()->entryWriteMode);
    }

    /**
     * Parsed through Craft's own helper, so anything the control panel accepts
     * from a human is accepted here too.
     *
     * `assumeSystemTimeZone: true` is the load-bearing argument. Craft's default
     * reads a naive string as UTC, while our read path prints dates as a naive
     * `Y-m-d H:i:s` already converted to the system timezone
     * (elements\Attributes::date()). With the default, reading an entry and
     * writing it straight back would shift every date by the site's offset, and
     * shift it again on the next round trip. The payload contract is that what
     * get_entry returns is what the write tools accept, so the two ends have to
     * agree on what a bare timestamp means. An explicit offset in the string
     * still wins, exactly as it does in the control panel.
     */
    private static function date(string $name, ?string $value): ?DateTime {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parsed = DateTimeHelper::toDateTime($value, assumeSystemTimeZone: true);

        return $parsed === false
            ? throw new ToolCallException("Could not read '{$value}' as a date for {$name}")
            : DateTime::createFromInterface($parsed);
    }
}
