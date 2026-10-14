<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use Closure;
use DateTimeImmutable;
use stimmt\craft\Mcp\http\Scope;

/**
 * The authorization endpoint's logic: check a request, then turn the user's
 * consent into a grant and a single-use code.
 *
 * WHY two kinds of failure: until the client and its redirect URI are known
 * to be genuine, an error must be shown to the user, never redirected,
 * otherwise this endpoint becomes an open redirector. Once they are, errors go
 * back to the app the way RFC 6749 section 4.1.2.1 describes.
 *
 * @author stimmt.digital
 */
final readonly class Authorizer {
    private const int CODE_TTL_SECONDS = 60;

    public function __construct(
        private OAuthStore $store,
        private OAuthConfig $config,
        private ?Closure $now = null,
    ) {
    }

    /**
     * @param array<string, mixed> $params the authorization request's query parameters
     * @throws OAuthError not redirectable: show it to the user
     */
    public function identify(array $params): OAuthClient {
        $client = $this->store->findClient($this->string($params, 'client_id') ?? '');
        if ($client === null) {
            throw OAuthError::invalidClient('This app is not registered with this site. Remove the connector and add it again.');
        }

        $redirectUri = $this->string($params, 'redirect_uri');
        if ($redirectUri === null || !$client->allowsRedirect($redirectUri)) {
            throw OAuthError::invalidRequest('The redirect URI does not match the one this app registered.');
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $params
     * @throws OAuthError redirectable once identify() has passed
     */
    public function validate(OAuthClient $client, array $params): AuthorizeRequest {
        if ($this->string($params, 'response_type') !== 'code') {
            throw new OAuthError('unsupported_response_type', 'Only response_type=code is supported.');
        }

        Pkce::assertChallenge($this->string($params, 'code_challenge'), $this->string($params, 'code_challenge_method'));

        $resource = $this->string($params, 'resource');
        if ($resource !== null && rtrim($resource, '/') !== rtrim($this->config->resource, '/')) {
            throw new OAuthError('invalid_target', 'This server only issues tokens for ' . $this->config->resource . '.');
        }

        return new AuthorizeRequest(
            $client,
            (string) $this->string($params, 'redirect_uri'),
            $this->string($params, 'state'),
            (string) $this->string($params, 'code_challenge'),
            $this->requestedScopes($this->string($params, 'scope')),
        );
    }

    /**
     * Record the consent and return where to send the user's browser next.
     */
    public function approve(AuthorizeRequest $request, int $userId, Scope $scope): string {
        if ($scope->isDisabled($this->config->disabledScopes)) {
            throw OAuthError::invalidRequest('That scope is disabled on this install.');
        }

        // The app's request is the ceiling: a consent form tampered with, or a
        // caller that skipped it, cannot hand out more than was asked for.
        if (!in_array($scope, $request->requestedScopes, true)) {
            throw new OAuthError('invalid_scope', 'The app did not ask for the "' . $scope->value . '" scope.');
        }

        $grant = $this->store->insertGrant(new Grant((int) $request->client->id, $userId, $scope));
        $code = Secret::generate();
        $this->store->insertCode(
            new AuthorizationCode((int) $grant->id, $request->redirectUri, $request->codeChallenge, $this->now()->modify('+' . self::CODE_TTL_SECONDS . ' seconds')),
            Secret::hash($code),
        );

        return $request->redirectWith(['code' => $code], $this->config->issuer());
    }

    public function deny(AuthorizeRequest $request): string {
        return $request->redirectWith(['error' => 'access_denied', 'error_description' => 'The user declined.'], $this->config->issuer());
    }

    public function errorRedirect(AuthorizeRequest|OAuthClient $target, array $params, OAuthError $error): string {
        $request = $target instanceof AuthorizeRequest
            ? $target
            : new AuthorizeRequest($target, (string) $this->string($params, 'redirect_uri'), $this->string($params, 'state'), '', []);

        return $request->redirectWith($error->toArray(), $this->config->issuer());
    }

    /**
     * Space-separated scopes, kept to the ones this install issues. An absent
     * scope asks for whatever is on offer, and the user still picks one.
     *
     * @return Scope[]
     */
    private function requestedScopes(?string $scope): array {
        $offered = $this->config->scopes();
        if ($scope === null || trim($scope) === '') {
            return $offered;
        }

        $asked = preg_split('/\s+/', trim($scope)) ?: [];

        return array_values(array_filter($offered, static fn (Scope $s): bool => in_array($s->value, $asked, true)));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function string(array $params, string $key): ?string {
        $value = $params[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function now(): DateTimeImmutable {
        return $this->now !== null ? ($this->now)() : new DateTimeImmutable();
    }
}
