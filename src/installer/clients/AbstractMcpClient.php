<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\clients;

use InvalidArgumentException;
use stimmt\craft\Mcp\installer\contracts\McpClientInterface;
use stimmt\craft\Mcp\installer\EnvironmentDetector;

/**
 * Base class for MCP client implementations.
 *
 * Provides shared configuration generation logic for DDEV and native PHP environments.
 */
abstract class AbstractMcpClient implements McpClientInterface {
    protected const BIN_PATH = 'vendor/stimmt/craft-mcp/bin/mcp-server';

    public function __construct(
        protected readonly string $projectRoot,
        protected readonly EnvironmentDetector $envDetector,
    ) {
    }

    public function requiresAbsolutePaths(): bool {
        return false;
    }

    public function generateServerConfig(string $environment, ?string $projectPath = null): array {
        return match ($environment) {
            EnvironmentDetector::DDEV => $this->buildDdevConfig($projectPath),
            EnvironmentDetector::NATIVE => $this->buildNativeConfig($projectPath),
            default => throw new InvalidArgumentException("Unknown environment: {$environment}"),
        };
    }

    /**
     * Build configuration for DDEV environment.
     *
     * @param string|null $projectPath Absolute project path (for clients that need it)
     * @return array{command: string, args: string[], cwd?: string}
     */
    protected function buildDdevConfig(?string $projectPath): array {
        $config = [
            'command' => $this->getDdevCommand($projectPath),
            'args' => ['exec', 'php', self::BIN_PATH],
        ];

        if ($projectPath !== null && $this->requiresAbsolutePaths()) {
            $config['cwd'] = $projectPath;
        }

        return $config;
    }

    /**
     * Build configuration for native PHP environment.
     *
     * @param string|null $projectPath Absolute project path (for clients that need it)
     * @return array{command: string, args: string[], cwd?: string}
     */
    protected function buildNativeConfig(?string $projectPath): array {
        $config = [
            'command' => $this->getPhpCommand($projectPath),
            'args' => [self::BIN_PATH],
        ];

        if ($projectPath !== null && $this->requiresAbsolutePaths()) {
            $config['cwd'] = $projectPath;
        }

        return $config;
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
