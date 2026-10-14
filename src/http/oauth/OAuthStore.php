<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use DateTimeImmutable;

/**
 * Persistence boundary for the OAuth connector. Codes and refresh tokens are
 * keyed by the sha256 of their plaintext, which is never stored.
 *
 * The two mark* methods are compare-and-set: they succeed only for the caller
 * that flips the row from unused to used, so two requests racing with the
 * same code or refresh token cannot both win.
 *
 * @author stimmt.digital
 */
interface OAuthStore {
    public function findClient(string $clientId): ?OAuthClient;

    public function findClientById(int $id): ?OAuthClient;

    public function insertClient(OAuthClient $client): OAuthClient;

    public function touchClient(int $id, DateTimeImmutable $when): void;

    public function findGrant(int $id): ?Grant;

    public function insertGrant(Grant $grant): Grant;

    public function revokeGrant(int $id, DateTimeImmutable $when): void;

    /** @return Grant[] live grants, newest first; every user's when $userId is null */
    public function liveGrants(?int $userId = null): array;

    public function insertCode(AuthorizationCode $code, string $hash): void;

    public function findCode(string $hash): ?AuthorizationCode;

    public function markCodeUsed(string $hash, DateTimeImmutable $when): bool;

    public function insertRefreshToken(RefreshToken $token, string $hash): void;

    public function findRefreshToken(string $hash): ?RefreshToken;

    public function markRefreshTokenUsed(string $hash, DateTimeImmutable $when): bool;

    /** Remove what can no longer be used. Returns rows removed. */
    public function collectGarbage(DateTimeImmutable $now, DateTimeImmutable $unusedClientsBefore): int;
}
