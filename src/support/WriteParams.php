<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

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

    public static function mode(?string $mode): WriteMode {
        if ($mode !== null) {
            return WriteMode::tryFrom(strtolower($mode))
                ?? throw new ToolCallException("Unknown mode '{$mode}'; use draft or live");
        }

        return WriteMode::fromSetting(Mcp::settings()->entryWriteMode);
    }
}
