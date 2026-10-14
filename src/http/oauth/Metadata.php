<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use stimmt\craft\Mcp\http\Scope;

/**
 * The two discovery documents a client reads before anything else: protected
 * resource metadata (RFC 9728), which names the authorization server, and
 * that server's own metadata (RFC 8414), which names its endpoints and what it
 * supports.
 *
 * @author stimmt.digital
 */
final readonly class Metadata {
    public function __construct(private OAuthConfig $config) {
    }

    /**
     * @return array<string, mixed>
     */
    public function protectedResource(): array {
        return [
            'resource' => $this->config->resource,
            'authorization_servers' => [$this->config->issuer()],
            'scopes_supported' => $this->scopeValues(),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Craft MCP',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function authorizationServer(): array {
        return [
            'issuer' => $this->config->issuer(),
            'authorization_endpoint' => $this->config->authorizationEndpoint,
            'token_endpoint' => $this->config->tokenEndpoint(),
            'registration_endpoint' => $this->config->registrationEndpoint(),
            'scopes_supported' => $this->scopeValues(),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => [Pkce::METHOD],
            'token_endpoint_auth_methods_supported' => ['none'],
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * @return string[]
     */
    private function scopeValues(): array {
        return array_map(static fn (Scope $scope): string => $scope->value, $this->config->scopes());
    }
}
