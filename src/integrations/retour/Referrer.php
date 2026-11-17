<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\retour;

/**
 * The referrer Retour stored with a 404, made safe to hand to a model.
 *
 * WHY the query string goes: Retour only strips it when its own
 * stripQueryStringFromStats setting is on (Statistics.php:186), and a
 * referrer's query is where search terms, email addresses and one-time tokens
 * travel. Where a visitor came from is the useful part; what they typed there
 * is not ours to pass on.
 */
final class Referrer {
    public static function withoutQuery(string $referrer): ?string {
        $bare = trim((string) preg_replace('/[?#].*$/s', '', trim($referrer)));

        return $bare === '' ? null : $bare;
    }
}
