<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use DateTimeImmutable;

/**
 * Persistence boundary for HTTP tokens; keyed by the sha256 hash of the
 * plaintext, which is never stored.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
interface TokenStore {
    public function findByHash(string $hash): ?Token;

    /** Persist and return the token with its id assigned. */
    public function insert(Token $token, string $hash): Token;

    public function delete(int $id): bool;

    /** @return Token[] */
    public function all(): array;

    public function touch(int $id, DateTimeImmutable $when): void;
}
