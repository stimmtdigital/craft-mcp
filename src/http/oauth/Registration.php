<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use Closure;
use DateTimeImmutable;

/**
 * Dynamic Client Registration (RFC 7591), restricted to what this server
 * supports: public clients using the authorization code grant with PKCE, and
 * redirect URIs on the install's allowlist.
 *
 * @author stimmt.digital
 */
final readonly class Registration {
    private const int MAX_NAME_LENGTH = 100;

    public function __construct(
        private OAuthStore $store,
        private RedirectUris $redirectUris,
        private ?Closure $now = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata the client's registration request
     * @return array<string, mixed> the registration response
     */
    public function register(array $metadata): array {
        $redirectUris = $this->redirectUris($metadata['redirect_uris'] ?? null);
        $this->assertPublicClient($metadata);
        $this->assertSubset($metadata['grant_types'] ?? null, ['authorization_code', 'refresh_token'], 'grant_types');
        $this->assertSubset($metadata['response_types'] ?? null, ['code'], 'response_types');

        $client = $this->store->insertClient(new OAuthClient(
            'mcpc_' . Secret::generate(18),
            $this->name($metadata['client_name'] ?? null),
            $redirectUris,
        ));

        return [
            'client_id' => $client->clientId,
            'client_id_issued_at' => $this->now()->getTimestamp(),
            'client_name' => $client->name,
            'redirect_uris' => $client->redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * @return string[]
     */
    private function redirectUris(mixed $uris): array {
        if (!is_array($uris) || $uris === []) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must list at least one redirect URI.');
        }

        foreach ($uris as $uri) {
            if (!is_string($uri) || !$this->redirectUris->allows($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'This site does not accept that redirect URI. Its owner can allow it with the oauthRedirectUris setting.');
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function assertPublicClient(array $metadata): void {
        $method = $metadata['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw new OAuthError('invalid_client_metadata', 'Only public clients are supported: token_endpoint_auth_method must be "none".');
        }
    }

    /**
     * @param string[] $supported
     */
    private function assertSubset(mixed $values, array $supported, string $field): void {
        if ($values === null) {
            return;
        }

        if (!is_array($values) || array_diff($values, $supported) !== []) {
            throw new OAuthError('invalid_client_metadata', sprintf('%s may only contain: %s.', $field, implode(', ', $supported)));
        }
    }

    /**
     * Shown on the consent screen, so it is cleaned and capped; the screen
     * also marks it as the app's own claim rather than something verified.
     */
    private function name(mixed $name): string {
        $clean = is_string($name) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name)) : '';

        return $clean === '' ? 'MCP client' : mb_substr($clean, 0, self::MAX_NAME_LENGTH);
    }

    private function now(): DateTimeImmutable {
        return $this->now !== null ? ($this->now)() : new DateTimeImmutable();
    }
}
