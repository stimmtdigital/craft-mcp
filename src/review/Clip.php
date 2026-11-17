<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * Shortens a long text value for a review response.
 *
 * WHY: a diff exists to be read next to another diff, and a rich-text body is
 * routinely tens of kilobytes. Two of those per changed field would push the
 * response past the install's byte budget and get the whole review refused,
 * when what the reader needs is to see THAT the body changed and how it
 * starts. The flag travels with the value so a shortened string is never
 * mistaken for the whole one.
 */
final class Clip {
    public const int MAX_CHARS = 500;

    /**
     * @return array{value: mixed, truncated: bool}
     */
    public static function of(mixed $value, int $max = self::MAX_CHARS): array {
        if (!is_string($value) || mb_strlen($value) <= $max) {
            return ['value' => $value, 'truncated' => false];
        }

        return ['value' => mb_substr($value, 0, $max), 'truncated' => true];
    }
}
