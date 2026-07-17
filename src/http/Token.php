<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use DateTimeImmutable;

/**
 * Immutable token facts. The plaintext secret never lives here; storage sees
 * only its hash.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Token {
    public function __construct(
        public string $name,
        public int $userId,
        public Scope $scope,
        public ?DateTimeImmutable $expiryDate = null,
        public ?DateTimeImmutable $lastUsedAt = null,
        public ?int $id = null,
        public ?DateTimeImmutable $dateCreated = null,
    ) {
    }

    public function expired(DateTimeImmutable $now): bool {
        return $this->expiryDate !== null && $this->expiryDate < $now;
    }
}
