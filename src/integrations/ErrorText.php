<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations;

/**
 * A third-party error message, cut down to what explains the failure. Used
 * for Formie delivery errors and for exceptions SEOmatic throws mid-scan.
 *
 * WHY scrubbed: CRMs and mail providers echo the rejected record back in
 * their errors ("contact jane@... already exists"), so the error text carries
 * the visitor's personal data even when the submission's field values are
 * never read. Emails and phone numbers are the identifiers those messages
 * actually repeat; this is a best-effort net, not an anonymiser.
 */
final class ErrorText {
    public const int MAX_LENGTH = 500;

    private const string EMAIL = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

    /**
     * A run of digits with the separators phone numbers are written with.
     * Judged per match below, because the same shape covers dates and ids.
     */
    private const string PHONE_CANDIDATE = '/(?<![\w-])\+?\(?\d[\d\s().\-]{5,}\d(?![\w-])/';

    /** E.164 allows at most 15 digits; fewer than 9 is an id or a count. */
    private const int MIN_PHONE_DIGITS = 9;

    private const int MAX_PHONE_DIGITS = 15;

    public static function clean(string $message): string {
        $text = trim((string) preg_replace('/\s+/u', ' ', $message));
        $text = (string) preg_replace(self::EMAIL, '[email]', $text);
        $text = (string) preg_replace_callback(self::PHONE_CANDIDATE, self::phone(...), $text);

        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_LENGTH) . '…';
    }

    /**
     * @param array<int, string> $match
     */
    private static function phone(array $match): string {
        $candidate = $match[0];

        // A timestamp has the right digit count and separators, and is
        // exactly what a failure report needs to keep.
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $candidate) === 1) {
            return $candidate;
        }

        $digits = strlen((string) preg_replace('/\D/', '', $candidate));

        return $digits >= self::MIN_PHONE_DIGITS && $digits <= self::MAX_PHONE_DIGITS ? '[phone]' : $candidate;
    }
}
