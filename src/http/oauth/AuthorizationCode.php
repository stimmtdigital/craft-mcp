<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use DateTimeImmutable;

/**
 * A single-use authorization code. Stored by hash only; the plaintext goes
 * once to the redirect URI.
 *
 * @author stimmt.digital
 */
final readonly class AuthorizationCode {
    public function __construct(
        public int $grantId,
        public string $redirectUri,
        public string $codeChallenge,
        public DateTimeImmutable $expiryDate,
        public ?DateTimeImmutable $dateUsed = null,
    ) {
    }
}
