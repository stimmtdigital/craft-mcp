<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use DateTimeImmutable;
use stimmt\craft\Mcp\http\oauth\AuthorizationCode;
use stimmt\craft\Mcp\http\oauth\Grant;
use stimmt\craft\Mcp\http\oauth\OAuthClient;
use stimmt\craft\Mcp\http\oauth\OAuthStore;
use stimmt\craft\Mcp\http\oauth\RefreshToken;

/**
 * Passes every call to another store, so a test can override one method to
 * inject something happening between two steps of an exchange.
 */
class ForwardingOAuthStore implements OAuthStore {
    public function __construct(private readonly OAuthStore $inner) {
    }

    public function findClient(string $clientId): ?OAuthClient {
        return $this->inner->findClient($clientId);
    }

    public function findClientById(int $id): ?OAuthClient {
        return $this->inner->findClientById($id);
    }

    public function insertClient(OAuthClient $client): OAuthClient {
        return $this->inner->insertClient($client);
    }

    public function touchClient(int $id, DateTimeImmutable $when): void {
        $this->inner->touchClient($id, $when);
    }

    public function findGrant(int $id): ?Grant {
        return $this->inner->findGrant($id);
    }

    public function insertGrant(Grant $grant): Grant {
        return $this->inner->insertGrant($grant);
    }

    public function revokeGrant(int $id, DateTimeImmutable $when): void {
        $this->inner->revokeGrant($id, $when);
    }

    public function liveGrants(?int $userId = null): array {
        return $this->inner->liveGrants($userId);
    }

    public function insertCode(AuthorizationCode $code, string $hash): void {
        $this->inner->insertCode($code, $hash);
    }

    public function findCode(string $hash): ?AuthorizationCode {
        return $this->inner->findCode($hash);
    }

    public function markCodeUsed(string $hash, DateTimeImmutable $when): bool {
        return $this->inner->markCodeUsed($hash, $when);
    }

    public function insertRefreshToken(RefreshToken $token, string $hash): void {
        $this->inner->insertRefreshToken($token, $hash);
    }

    public function findRefreshToken(string $hash): ?RefreshToken {
        return $this->inner->findRefreshToken($hash);
    }

    public function markRefreshTokenUsed(string $hash, DateTimeImmutable $when): bool {
        return $this->inner->markRefreshTokenUsed($hash, $when);
    }

    public function collectGarbage(DateTimeImmutable $now, DateTimeImmutable $unusedClientsBefore): int {
        return $this->inner->collectGarbage($now, $unusedClientsBefore);
    }
}
