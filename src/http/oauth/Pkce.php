<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

/**
 * Proof Key for Code Exchange, S256 only (RFC 7636). The `plain` method is
 * refused outright: it protects nothing against an attacker who can read the
 * authorization request, which is the case PKCE exists for.
 *
 * @author stimmt.digital
 */
final class Pkce {
    public const string METHOD = 'S256';

    /** The verifier alphabet and length bounds from RFC 7636 section 4.1. */
    private const string VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    /** An S256 challenge is the base64url of a 32-byte digest: 43 characters. */
    private const string CHALLENGE_PATTERN = '/^[A-Za-z0-9\-_]{43}$/';

    public static function assertChallenge(?string $challenge, ?string $method): void {
        if ($method !== self::METHOD) {
            throw OAuthError::invalidRequest('code_challenge_method must be S256.');
        }

        if ($challenge === null || preg_match(self::CHALLENGE_PATTERN, $challenge) !== 1) {
            throw OAuthError::invalidRequest('code_challenge must be an S256 challenge.');
        }
    }

    public static function verify(string $verifier, string $challenge): bool {
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            return false;
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $computed);
    }
}
