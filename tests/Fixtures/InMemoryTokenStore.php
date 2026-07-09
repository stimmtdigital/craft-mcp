<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use DateTimeImmutable;
use stimmt\craft\Mcp\http\Token;
use stimmt\craft\Mcp\http\TokenStore;

/**
 * Test double: hash-keyed in-memory token storage with a touch counter.
 */
final class InMemoryTokenStore implements TokenStore {
    /** @var array<string, Token> */
    private array $byHash = [];

    public int $touches = 0;

    private int $nextId = 1;

    public function findByHash(string $hash): ?Token {
        return $this->byHash[$hash] ?? null;
    }

    public function insert(Token $token, string $hash): Token {
        $stored = new Token($token->name, $token->userId, $token->scope, $token->expiryDate, $token->lastUsedAt, $this->nextId++);
        $this->byHash[$hash] = $stored;

        return $stored;
    }

    public function delete(int $id): bool {
        foreach ($this->byHash as $hash => $token) {
            if ($token->id === $id) {
                unset($this->byHash[$hash]);

                return true;
            }
        }

        return false;
    }

    public function all(): array {
        return array_values($this->byHash);
    }

    public function touch(int $id, DateTimeImmutable $when): void {
        $this->touches++;
        foreach ($this->byHash as $hash => $token) {
            if ($token->id === $id) {
                $this->byHash[$hash] = new Token($token->name, $token->userId, $token->scope, $token->expiryDate, $when, $token->id);
            }
        }
    }

    /** @return string[] */
    public function hashes(): array {
        return array_keys($this->byHash);
    }
}
