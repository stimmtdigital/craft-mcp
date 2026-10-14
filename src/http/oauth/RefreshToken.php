<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use DateTimeImmutable;

/**
 * A refresh token. Used once: refreshing marks it used and issues the next
 * one, so a second use means the token was copied, and the grant is revoked.
 *
 * @author stimmt.digital
 */
final readonly class RefreshToken {
    public function __construct(
        public int $grantId,
        public DateTimeImmutable $expiryDate,
        public ?DateTimeImmutable $dateUsed = null,
    ) {
    }
}
