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
 * Test double for the OAuth tables, with the same compare-and-set semantics
 * the database store gives the two mark*Used methods.
 */
final class InMemoryOAuthStore implements OAuthStore {
    /** @var array<int, OAuthClient> */
    public array $clients = [];

    /** @var array<int, Grant> */
    public array $grants = [];

    /** @var array<string, AuthorizationCode> */
    public array $codes = [];

    /** @var array<string, RefreshToken> */
    public array $refreshTokens = [];

    private int $nextId = 1;

    public function findClient(string $clientId): ?OAuthClient {
        foreach ($this->clients as $client) {
            if ($client->clientId === $clientId) {
                return $client;
            }
        }

        return null;
    }

    public function findClientById(int $id): ?OAuthClient {
        return $this->clients[$id] ?? null;
    }

    public function insertClient(OAuthClient $client): OAuthClient {
        $id = $this->nextId++;

        return $this->clients[$id] = new OAuthClient($client->clientId, $client->name, $client->redirectUris, $id, new DateTimeImmutable());
    }

    public function touchClient(int $id, DateTimeImmutable $when): void {
        $client = $this->clients[$id];
        $this->clients[$id] = new OAuthClient($client->clientId, $client->name, $client->redirectUris, $id, $client->dateCreated, $when);
    }

    public function findGrant(int $id): ?Grant {
        return $this->grants[$id] ?? null;
    }

    public function insertGrant(Grant $grant): Grant {
        $id = $this->nextId++;

        return $this->grants[$id] = new Grant($grant->clientRecordId, $grant->userId, $grant->scope, $id, new DateTimeImmutable());
    }

    public function revokeGrant(int $id, DateTimeImmutable $when): void {
        $grant = $this->grants[$id];
        $this->grants[$id] = new Grant($grant->clientRecordId, $grant->userId, $grant->scope, $id, $grant->dateCreated, $when);
    }

    public function liveGrants(?int $userId = null): array {
        return array_values(array_filter(
            $this->grants,
            static fn (Grant $grant): bool => !$grant->isRevoked() && ($userId === null || $grant->userId === $userId),
        ));
    }

    public function insertCode(AuthorizationCode $code, string $hash): void {
        $this->codes[$hash] = $code;
    }

    public function findCode(string $hash): ?AuthorizationCode {
        return $this->codes[$hash] ?? null;
    }

    public function markCodeUsed(string $hash, DateTimeImmutable $when): bool {
        $code = $this->codes[$hash] ?? null;
        if ($code === null || $code->dateUsed !== null) {
            return false;
        }

        $this->codes[$hash] = new AuthorizationCode($code->grantId, $code->redirectUri, $code->codeChallenge, $code->expiryDate, $when);

        return true;
    }

    public function insertRefreshToken(RefreshToken $token, string $hash): void {
        $this->refreshTokens[$hash] = $token;
    }

    public function findRefreshToken(string $hash): ?RefreshToken {
        return $this->refreshTokens[$hash] ?? null;
    }

    public function markRefreshTokenUsed(string $hash, DateTimeImmutable $when): bool {
        $token = $this->refreshTokens[$hash] ?? null;
        if ($token === null || $token->dateUsed !== null) {
            return false;
        }

        $this->refreshTokens[$hash] = new RefreshToken($token->grantId, $token->expiryDate, $when);

        return true;
    }

    public function collectGarbage(DateTimeImmutable $now, DateTimeImmutable $unusedClientsBefore): int {
        return 0;
    }
}
