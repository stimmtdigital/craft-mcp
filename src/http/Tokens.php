<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use Closure;
use DateTimeImmutable;

/**
 * HTTP token lifecycle: create (plaintext shown exactly once), authenticate
 * (hash lookup, expiry check, hourly lastUsedAt stamp), revoke, list. Pure
 * PHP; user status checks belong to the caller that loads the Craft user.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Tokens {
    private const string PREFIX = 'mcp_';

    private const int SECRET_LENGTH = 40;

    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const int TOUCH_INTERVAL_SECONDS = 3600;

    public function __construct(
        private TokenStore $store,
        private ?Closure $now = null,
    ) {
    }

    /**
     * @return array{token: Token, plaintext: string}
     */
    public function create(int $userId, Scope $scope, string $name, ?int $expiresInDays = null): array {
        $plaintext = self::PREFIX . $this->secret();
        $expiry = $expiresInDays === null ? null : $this->now()->modify("+{$expiresInDays} days");
        $token = $this->store->insert(new Token($name, $userId, $scope, $expiry), self::hash($plaintext));

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    public function authenticate(string $plaintext): ?Token {
        $token = $this->store->findByHash(self::hash($plaintext));
        if ($token === null || $token->expired($this->now())) {
            return null;
        }

        $this->touchIfStale($token);

        return $token;
    }

    public function revoke(string $nameOrId): bool {
        foreach ($this->store->all() as $token) {
            if ($token->name === $nameOrId || (string) $token->id === $nameOrId) {
                return $token->id !== null && $this->store->delete($token->id);
            }
        }

        return false;
    }

    /**
     * Delete a token by its numeric id only. Callers that authorize against a
     * specific resolved token (the control panel) must use this, never the
     * name-or-id revoke(): a token whose name equals another token's id string
     * would otherwise let the wrong row match under an authorized id.
     */
    public function revokeById(int $id): bool {
        return $this->store->delete($id);
    }

    /**
     * Issue a fresh secret for an existing token, preserving its name, user,
     * scope, and exact expiry, then invalidate the old one. Inserts the new
     * token before deleting the old so a failed delete leaves the old still
     * working rather than leaving the user with nothing.
     *
     * @return array{token: Token, plaintext: string}
     */
    public function regenerate(Token $token): array {
        $plaintext = self::PREFIX . $this->secret();
        $fresh = $this->store->insert(
            new Token($token->name, $token->userId, $token->scope, $token->expiryDate),
            self::hash($plaintext),
        );

        if ($token->id !== null) {
            $this->store->delete($token->id);
        }

        return ['token' => $fresh, 'plaintext' => $plaintext];
    }

    /**
     * @return Token[]
     */
    public function list(): array {
        return $this->store->all();
    }

    /**
     * @return Token[]
     */
    public function listFor(int $userId): array {
        return array_values(array_filter(
            $this->list(),
            static fn (Token $token): bool => $token->userId === $userId,
        ));
    }

    public static function hash(string $plaintext): string {
        return hash('sha256', $plaintext);
    }

    private function touchIfStale(Token $token): void {
        if ($token->id === null) {
            return;
        }

        $now = $this->now();
        $stale = $token->lastUsedAt === null
            || $now->getTimestamp() - $token->lastUsedAt->getTimestamp() >= self::TOUCH_INTERVAL_SECONDS;

        if ($stale) {
            $this->store->touch($token->id, $now);
        }
    }

    private function secret(): string {
        $secret = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= self::ALPHABET[random_int(0, $max)];
        }

        return $secret;
    }

    private function now(): DateTimeImmutable {
        return $this->now !== null ? ($this->now)() : new DateTimeImmutable();
    }
}
