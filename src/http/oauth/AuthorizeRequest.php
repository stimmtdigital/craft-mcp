<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use stimmt\craft\Mcp\http\Scope;

/**
 * An authorization request whose client and redirect URI have been checked,
 * so errors from here on can safely be sent back to that redirect URI.
 *
 * @author stimmt.digital
 */
final readonly class AuthorizeRequest {
    /**
     * @param Scope[] $requestedScopes the scopes asked for that this install knows, in Scope order
     */
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public ?string $state,
        public string $codeChallenge,
        public array $requestedScopes,
    ) {
    }

    /** The host the user is sending their consent to, shown on the consent screen. */
    public function redirectHost(): string {
        return (string) parse_url($this->redirectUri, PHP_URL_HOST);
    }

    /**
     * @param array<string, string> $params
     */
    public function redirectWith(array $params, string $issuer): string {
        $params += array_filter(['state' => $this->state], static fn (?string $value): bool => $value !== null);
        $params['iss'] = $issuer;
        $separator = str_contains($this->redirectUri, '?') ? '&' : '?';

        return $this->redirectUri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
