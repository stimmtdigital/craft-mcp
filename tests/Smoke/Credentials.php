<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use RuntimeException;
use SensitiveParameter;

/**
 * The bearer token an HTTP profile connects with, and the endpoint it connects
 * to.
 *
 * WHY minted here rather than configured: a token is a secret, and a secret
 * that lives in a repository is a secret that leaks. Each profile mints a
 * Craft personal access token for the install's admin with exactly the
 * profile's scopes, uses it for one run, and revokes it afterwards, so nothing
 * durable exists to commit by accident. Nothing on this class is ever written
 * to a snapshot or printed.
 *
 * A person who would rather supply their own exports CRAFT_MCP_SMOKE_TOKEN_READ
 * (or _WRITE, or _ADMIN), and it is used as-is and never revoked.
 *
 * The endpoint is Craft's own answer to "where is the MCP server"
 * (`Settings::url($route)`), unless CRAFT_MCP_SMOKE_ENDPOINT says otherwise.
 * It has to on a headless install: from the console Craft builds that url on
 * the site's base url, which is the front end, not the host Craft answers on.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Credentials {
    private const string ENDPOINT_VARIABLE = 'CRAFT_MCP_SMOKE_ENDPOINT';

    private const string TOKEN_VARIABLE = 'CRAFT_MCP_SMOKE_TOKEN_';

    private function __construct(
        public readonly string $endpoint,
        #[SensitiveParameter]
        public readonly string $token,
        private readonly ?string $mintedAs,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public static function for(string $level, array $scopes, string $runId): self {
        $supplied = getenv(self::TOKEN_VARIABLE . strtoupper($level));
        if (is_string($supplied) && $supplied !== '') {
            return new self(self::endpoint(), $supplied, null);
        }

        $name = "smoke-{$runId}-{$level}";
        $answer = Install::script('console.php', ['mint', $name, implode(',', $scopes)]);
        $token = $answer['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException("Minting {$name} answered without a token.");
        }

        return new self(self::endpoint(), $token, $name);
    }

    /**
     * Revokes what this run minted. A supplied token is left alone: it belongs
     * to whoever exported it.
     */
    public function release(): void {
        if ($this->mintedAs !== null) {
            Install::script('console.php', ['revoke', $this->mintedAs]);
        }
    }

    private static function endpoint(): string {
        $configured = getenv(self::ENDPOINT_VARIABLE);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $endpoint = Install::info()['endpoint'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('No endpoint: export ' . self::ENDPOINT_VARIABLE . ' with the url of Craft\'s MCP server.');
        }

        return $endpoint;
    }
}
