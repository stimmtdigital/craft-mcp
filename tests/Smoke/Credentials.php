<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use RuntimeException;

/**
 * The bearer token an HTTP profile connects with, and the endpoint it connects
 * to.
 *
 * WHY minted here rather than configured: a token is a secret, and a secret
 * that lives in a repository is a secret that leaks. Each profile mints its own
 * through the plugin's own console command, uses it for one run, and revokes it
 * afterwards, so nothing durable exists to commit by accident. Nothing on this
 * class is ever written to a snapshot.
 *
 * A person who would rather supply their own exports
 * CRAFT_MCP_SMOKE_TOKEN_FULL (or _CONTENT, or _READONLY) together with
 * CRAFT_MCP_SMOKE_ENDPOINT, and nothing is minted or revoked.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Credentials {
    private const string ENDPOINT_VARIABLE = 'CRAFT_MCP_SMOKE_ENDPOINT';

    private const string TOKEN_VARIABLE = 'CRAFT_MCP_SMOKE_TOKEN_';

    /** Matches the plaintext the console command prints exactly once. */
    private const string TOKEN_PATTERN = '/\bmcp_[A-Za-z0-9]{40}\b/';

    /** The endpoint the plugin itself tells clients to use, from the snippet. */
    private const string URL_PATTERN = '/"url"\s*:\s*"([^"]+)"/';

    private function __construct(
        public readonly string $endpoint,
        public readonly string $token,
        private readonly ?string $mintedAs,
    ) {
    }

    public static function for(string $scope, string $runId): self {
        $supplied = getenv(self::TOKEN_VARIABLE . strtoupper($scope));
        if (is_string($supplied) && $supplied !== '') {
            return new self(self::configuredEndpoint(), $supplied, null);
        }

        $name = "smoke-{$runId}-{$scope}";
        $output = self::craft(['mcp/tokens/create', '--user=' . self::admin(), '--scope=' . $scope, '--name=' . $name, '--interactive=0']);

        return new self(self::endpoint($output), self::token($output), $name);
    }

    /**
     * Revokes what this run minted. A supplied token is left alone: it belongs
     * to whoever exported it.
     */
    public function release(): void {
        if ($this->mintedAs !== null) {
            self::craft(['mcp/tokens/revoke', $this->mintedAs, '--interactive=0']);
        }
    }

    /**
     * Craft's own admin listing, so the harness needs no configured identity
     * and carries no install-specific username. Full scope means code
     * execution, so an admin is the only honest identity for it anyway.
     */
    private static function admin(): string {
        $output = self::craft(['users/list-admins']);
        if (preg_match('/\(([^)\s]+@[^)\s]+)\)/', $output, $matches) !== 1) {
            throw new RuntimeException("Could not read an admin user from: {$output}");
        }

        return $matches[1];
    }

    private static function token(string $output): string {
        if (preg_match(self::TOKEN_PATTERN, $output, $matches) !== 1) {
            throw new RuntimeException('The token command printed no token: ' . self::redact($output));
        }

        return $matches[0];
    }

    /**
     * The endpoint the plugin advertises in its own client snippet, so the
     * harness cannot disagree with the install about where the endpoint is.
     * With the snippet turned off (showClientConfigSnippet), the environment
     * has to say.
     */
    private static function endpoint(string $output): string {
        if (preg_match(self::URL_PATTERN, $output, $matches) === 1) {
            return $matches[1];
        }

        return self::configuredEndpoint();
    }

    private static function configuredEndpoint(): string {
        $endpoint = getenv(self::ENDPOINT_VARIABLE);
        if (!is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('No endpoint: export ' . self::ENDPOINT_VARIABLE . ' with the url of the MCP HTTP endpoint.');
        }

        return $endpoint;
    }

    /**
     * @param list<string> $arguments
     */
    private static function craft(array $arguments): string {
        $command = 'cd ' . escapeshellarg(self::root()) . ' && php craft '
            . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';

        $output = shell_exec($command);
        if (!is_string($output)) {
            throw new RuntimeException('Could not run: ' . implode(' ', $arguments));
        }

        return $output;
    }

    /**
     * The Craft installation this plugin is installed into, found the same way
     * bin/mcp-server finds it, so the harness works from a path repository and
     * from vendor/ alike.
     */
    private static function root(): string {
        $directory = __DIR__;

        for ($level = 0; $level < 10; $level++) {
            $directory = dirname($directory);
            if (is_file($directory . '/bootstrap.php') && is_file($directory . '/craft')) {
                return $directory;
            }
        }

        throw new RuntimeException('Could not find the Craft installation above ' . __DIR__);
    }

    /**
     * A failure message must never carry the secret it failed to parse.
     */
    private static function redact(string $output): string {
        return (string) preg_replace(self::TOKEN_PATTERN, '<token>', $output);
    }
}
