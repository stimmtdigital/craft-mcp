<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

/**
 * Save mode for element writes.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum WriteMode: string {
    case Draft = 'draft';
    case Live = 'live';

    public static function fromSetting(string $value): self {
        return self::from(strtolower($value));
    }
}
