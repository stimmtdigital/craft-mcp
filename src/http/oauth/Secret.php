<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

/**
 * Random identifiers and secrets for the OAuth flow, and the one hash they
 * are stored under. 32 bytes of randomness, base64url without padding, so a
 * value is safe in a URL query string as-is.
 *
 * @author stimmt.digital
 */
final class Secret {
    public static function generate(int $bytes = 32): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $plaintext): string {
        return hash('sha256', $plaintext);
    }
}
