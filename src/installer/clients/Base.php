<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\clients;

use InvalidArgumentException;
use stimmt\craft\Mcp\installer\ConfigWriter;
use stimmt\craft\Mcp\installer\contracts\Client;
use stimmt\craft\Mcp\installer\contracts\ConfigFormat;
use stimmt\craft\Mcp\installer\EnvironmentDetector;

/**
 * Base class for MCP client implementations.
 *
 * Every client starts Craft's own stdio server, `craft mcp/serve`, acting as
 * one Craft user: since 2.0 the plugin adds its tools to that server rather
 * than running one of its own.
 */
abstract class Base implements Client {
    protected const string CRAFT = 'craft';

    public function __construct(
        protected readonly string $projectRoot,
        protected readonly string $configRoot,
        protected readonly EnvironmentDetector $envDetector,
    ) {
    }

    public function requiresAbsolutePaths(): bool {
        return false;
    }

    /**
     * JSON with an `mcpServers` map, which every client but Codex reads.
     */
    public function configWriter(): ConfigFormat {
        return new ConfigWriter();
    }

    public function generateServerConfig(string $environment, string $user, ?string $projectPath = null, ?string $craftSubdirectory = null): array {
        return match ($environment) {
            EnvironmentDetector::DDEV => $this->buildDdevConfig($user, $projectPath, $craftSubdirectory),
            EnvironmentDetector::NATIVE => $this->buildNativeConfig($user, $projectPath, $craftSubdirectory),
            default => throw new InvalidArgumentException("Unknown environment: {$environment}"),
        };
    }

    /**
     * Build configuration for DDEV environment.
     *
     * @param string $user The Craft user the server acts as
     * @param string|null $projectPath Absolute project path (for clients that need it)
     * @param string|null $craftSubdirectory Relative path from project root to Craft root
     * @return array{command: string, args: string[], cwd?: string}
     */
    protected function buildDdevConfig(string $user, ?string $projectPath, ?string $craftSubdirectory): array {
        $config = [
            'command' => $this->getDdevCommand($projectPath),
            'args' => ['exec', 'php', ...$this->serveArgs($user, $craftSubdirectory)],
        ];

        if ($projectPath !== null && $this->requiresAbsolutePaths()) {
            $config['cwd'] = $projectPath;
        }

        return $config;
    }

    /**
     * Build configuration for native PHP environment.
     *
     * @param string $user The Craft user the server acts as
     * @param string|null $projectPath Absolute project path (for clients that need it)
     * @param string|null $craftSubdirectory Relative path from project root to Craft root
     * @return array{command: string, args: string[], cwd?: string}
     */
    protected function buildNativeConfig(string $user, ?string $projectPath, ?string $craftSubdirectory): array {
        $config = [
            'command' => $this->getPhpCommand($projectPath),
            'args' => $this->serveArgs($user, $craftSubdirectory),
        ];

        if ($projectPath !== null && $this->requiresAbsolutePaths()) {
            $config['cwd'] = $projectPath;
        }

        return $config;
    }

    /**
     * `craft mcp/serve --user=…`, with the craft script prefixed by the
     * subdirectory when Craft lives below the project root.
     *
     * @return list<string>
     */
    private function serveArgs(string $user, ?string $craftSubdirectory): array {
        $craft = $craftSubdirectory === null ? self::CRAFT : $craftSubdirectory . '/' . self::CRAFT;

        return [$craft, 'mcp/serve', '--user=' . $user];
    }

    /**
     * Get the ddev command path based on context.
     */
    private function getDdevCommand(?string $projectPath): string {
        if (!$this->shouldUseAbsolutePaths($projectPath)) {
            return 'ddev';
        }

        return $this->envDetector->getDdevBinaryPath() ?? 'ddev';
    }

    /**
     * Get the php command path based on context.
     */
    private function getPhpCommand(?string $projectPath): string {
        if (!$this->shouldUseAbsolutePaths($projectPath)) {
            return 'php';
        }

        return $this->envDetector->getPhpBinaryPath() ?? 'php';
    }

    /**
     * Check if absolute paths should be used.
     */
    private function shouldUseAbsolutePaths(?string $projectPath): bool {
        return $projectPath !== null && $this->requiresAbsolutePaths();
    }
}
