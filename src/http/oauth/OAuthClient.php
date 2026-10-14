<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use DateTimeImmutable;

/**
 * An app that registered itself through Dynamic Client Registration. Public
 * client: it holds no secret, so PKCE is what binds a code to it.
 *
 * @author stimmt.digital
 */
final readonly class OAuthClient {
    /**
     * @param string[] $redirectUris every one of them on the install's allowlist
     */
    public function __construct(
        public string $clientId,
        public string $name,
        public array $redirectUris,
        public ?int $id = null,
        public ?DateTimeImmutable $dateCreated = null,
        public ?DateTimeImmutable $lastUsedAt = null,
    ) {
    }

    public function allowsRedirect(string $uri): bool {
        return in_array($uri, $this->redirectUris, true);
    }
}
