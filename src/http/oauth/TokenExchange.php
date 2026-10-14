<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use Closure;
use DateTimeImmutable;
use stimmt\craft\Mcp\http\Tokens;

/**
 * The token endpoint's logic: an authorization code for the first tokens, a
 * refresh token for every pair after that.
 *
 * Refresh tokens rotate, as OAuth 2.1 requires for public clients: each one
 * is good for exactly one exchange. Presenting a code or refresh token that
 * was already used means a copy exists somewhere it should not, and the whole
 * grant is revoked, the legitimate app included, since the server cannot tell
 * which of the two holders is which.
 *
 * @author stimmt.digital
 */
final readonly class TokenExchange {
    public function __construct(
        private OAuthStore $store,
        private Tokens $tokens,
        private Grants $grants,
        private OAuthConfig $config,
        private ?Closure $now = null,
        private ?Closure $mayHold = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params the token request's form fields
     * @return array<string, int|string> the token response
     */
    public function exchange(array $params): array {
        return match ($params['grant_type'] ?? null) {
            'authorization_code' => $this->fromCode($params),
            'refresh_token' => $this->fromRefreshToken($params),
            default => throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.'),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int|string>
     */
    private function fromCode(array $params): array {
        $plaintext = $this->required($params, 'code');
        $hash = Secret::hash($plaintext);
        $code = $this->store->findCode($hash) ?? throw OAuthError::invalidGrant('Unknown authorization code.');

        if ($code->dateUsed !== null) {
            $this->grants->revoke($code->grantId);

            throw OAuthError::invalidGrant('This authorization code was already used; the connection has been revoked.');
        }

        if ($code->expiryDate < $this->now()) {
            throw OAuthError::invalidGrant('The authorization code has expired.');
        }

        $grant = $this->liveGrant($code->grantId, $this->required($params, 'client_id'));

        if (($params['redirect_uri'] ?? null) !== $code->redirectUri) {
            throw OAuthError::invalidGrant('redirect_uri does not match the authorization request.');
        }

        if (!Pkce::verify($this->required($params, 'code_verifier'), $code->codeChallenge)) {
            throw OAuthError::invalidGrant('The code_verifier does not match the code_challenge.');
        }

        $this->assertResource($params);

        if (!$this->store->markCodeUsed($hash, $this->now())) {
            throw OAuthError::invalidGrant('This authorization code was already used.');
        }

        return $this->issue($grant);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int|string>
     */
    private function fromRefreshToken(array $params): array {
        $hash = Secret::hash($this->required($params, 'refresh_token'));
        $refresh = $this->store->findRefreshToken($hash) ?? throw OAuthError::invalidGrant('Unknown refresh token.');

        if ($refresh->dateUsed !== null) {
            $this->grants->revoke($refresh->grantId);

            throw OAuthError::invalidGrant('This refresh token was already used; the connection has been revoked.');
        }

        if ($refresh->expiryDate < $this->now()) {
            throw OAuthError::invalidGrant('The refresh token has expired. Connect the app again.');
        }

        $grant = $this->liveGrant($refresh->grantId, $this->required($params, 'client_id'));
        $this->assertResource($params);

        if (!$this->store->markRefreshTokenUsed($hash, $this->now())) {
            $this->grants->revoke($refresh->grantId);

            throw OAuthError::invalidGrant('This refresh token was already used; the connection has been revoked.');
        }

        return $this->issue($grant);
    }

    private function liveGrant(int $grantId, string $clientId): Grant {
        $grant = $this->store->findGrant($grantId);
        if ($grant === null || $grant->isRevoked()) {
            throw OAuthError::invalidGrant('This connection was disconnected. Connect the app again.');
        }

        $client = $this->store->findClientById($grant->clientRecordId);
        if ($client === null || !hash_equals($client->clientId, $clientId)) {
            throw OAuthError::invalidGrant('This grant was issued to a different client.');
        }

        if ($grant->scope->isDisabled($this->config->disabledScopes)) {
            throw OAuthError::invalidGrant('The "' . $grant->scope->value . '" scope is disabled on this install.');
        }

        // Standing changes after consent: a user can lose the token
        // permission, lose admin while holding full, or be suspended. Asking
        // on every exchange keeps a rotating refresh token from carrying a
        // grant past the point the user could still approve it.
        if ($this->mayHold !== null && !($this->mayHold)($grant->userId, $grant->scope)) {
            $this->grants->revoke((int) $grant->id);

            throw OAuthError::invalidGrant('This user may no longer hold the "' . $grant->scope->value . '" scope; the connection has been revoked.');
        }

        $this->store->touchClient($grant->clientRecordId, $this->now());

        return $grant;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertResource(array $params): void {
        $resource = $params['resource'] ?? null;
        if (is_string($resource) && $resource !== '' && rtrim($resource, '/') !== rtrim($this->config->resource, '/')) {
            throw new OAuthError('invalid_target', 'This server only issues tokens for ' . $this->config->resource . '.');
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function issue(Grant $grant): array {
        $now = $this->now();
        $client = $this->store->findClientById($grant->clientRecordId);
        ['plaintext' => $access] = $this->tokens->issue(
            $grant->userId,
            $grant->scope,
            'OAuth: ' . ($client->name ?? 'app'),
            $now->modify('+' . $this->config->accessTokenTtl . ' seconds'),
            (int) $grant->id,
        );

        $refresh = Secret::generate();
        $this->store->insertRefreshToken(
            new RefreshToken((int) $grant->id, $now->modify('+' . $this->config->refreshTokenTtl . ' seconds')),
            Secret::hash($refresh),
        );

        // A Disconnect that landed after liveGrant() passed deleted the
        // grant's tokens before this one existed. Looking again after writing
        // makes the revoke win: what was just issued is removed with it.
        if ($this->store->findGrant((int) $grant->id)?->isRevoked() !== false) {
            $this->grants->revoke((int) $grant->id);

            throw OAuthError::invalidGrant('This connection was disconnected. Connect the app again.');
        }

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->config->accessTokenTtl,
            'refresh_token' => $refresh,
            'scope' => $grant->scope->value,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function required(array $params, string $key): string {
        $value = $params[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw OAuthError::invalidRequest($key . ' is required.');
        }

        return $value;
    }

    private function now(): DateTimeImmutable {
        return $this->now !== null ? ($this->now)() : new DateTimeImmutable();
    }
}
