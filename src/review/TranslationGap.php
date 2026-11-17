<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\review;

/**
 * Whether one translatable value on a target site still needs translating.
 *
 * A value is a gap when the source site has something and the target has
 * nothing ("empty"), or the very same thing ("same-as-source"). A value empty
 * on the source too is not a gap: there is nothing to translate yet.
 *
 * WHY values are compared in a comparable form rather than as read: text
 * compares as a string, so 3 and "3" agree. Related elements compare by
 * natural key, which the caller reads for BOTH sites in the source site's
 * terms, so "same-as-source" means "relates to the same elements". Nested
 * blocks compare by type and content in order, never by id, because each site
 * of a per-site Matrix field holds blocks of its own with ids of their own:
 * compared by id, an untranslated copy would always look translated.
 */
final class TranslationGap {
    public const string EMPTY = 'empty';

    public const string SAME_AS_SOURCE = 'same-as-source';

    /** Keys of a block that differ per site whatever the translation state. */
    private const array BLOCK_IDENTITY = ['position', 'collapsed', 'slug'];

    /**
     * @param array<string, mixed> $source values by handle on the source site
     * @param array<string, mixed> $target values by handle on the target site
     * @param list<string> $handles the handles that hold a separate value per site
     * @return list<array{field: string, reason: string}>
     */
    public static function ofEntry(array $source, array $target, array $handles): array {
        $gaps = [];
        foreach ($handles as $handle) {
            $reason = self::reason($source[$handle] ?? null, $target[$handle] ?? null);
            if ($reason !== null) {
                $gaps[] = ['field' => $handle, 'reason' => $reason];
            }
        }

        return $gaps;
    }

    public static function reason(mixed $source, mixed $target): ?string {
        if (self::isBlank($source)) {
            return null;
        }

        if (self::isBlank($target)) {
            return self::EMPTY;
        }

        return self::comparable($source) === self::comparable($target) ? self::SAME_AS_SOURCE : null;
    }

    /** Whitespace alone is as untranslated as nothing. */
    private static function isBlank(mixed $value): bool {
        return Values::isEmpty(is_string($value) ? trim($value) : $value);
    }

    private static function comparable(mixed $value): mixed {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        if (Values::isBlockList($value)) {
            return array_map(static fn (string $key): array => self::block($value[$key]), Values::blockOrder($value));
        }

        if (Values::isBlock($value)) {
            return self::block($value);
        }

        return is_array($value) ? Values::normalize(array_map(self::comparable(...), $value)) : $value;
    }

    /**
     * @param array<array-key, mixed> $block
     * @return array<array-key, mixed>
     */
    private static function block(array $block): array {
        $content = array_diff_key($block, array_flip(self::BLOCK_IDENTITY));
        $content['fields'] = array_map(self::comparable(...), $block['fields']);

        return Values::normalize($content);
    }
}
