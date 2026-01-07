<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer;

/**
 * Detects the development environment type (DDEV or native PHP).
 */
final readonly class EnvironmentDetector {
    public const string DDEV = 'ddev';

    public const string NATIVE = 'native';

    private const array DDEV_BINARY_PATHS = [
        '/usr/local/bin/ddev',
        '/opt/homebrew/bin/ddev',
        '/home/linuxbrew/.linuxbrew/bin/ddev',
    ];

    public function __construct(
        private string $projectRoot,
    ) {
    }

    /**
     * Detect the current environment type.
     *
     * @return string One of self::DDEV or self::NATIVE
     */
    public function detect(): string {
        if ($this->isDdevEnvironment()) {
            return self::DDEV;
        }

        return self::NATIVE;
    }

    /**
     * Check if running in a DDEV environment.
     */
    public function isDdevEnvironment(): bool {
        // Check for DDEV_PROJECT env var (most reliable when inside container)
        if (getenv('DDEV_PROJECT') !== false) {
            return true;
        }

        // Check for .ddev directory in project root
        return is_dir($this->projectRoot . '/.ddev');
    }

    /**
     * Get the absolute path to the DDEV binary.
     *
     * @return string|null Path to ddev binary, or null if not found
     */
    public function getDdevBinaryPath(): ?string {
        // Check common installation paths
        foreach (self::DDEV_BINARY_PATHS as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Fall back to `which ddev`
        return $this->findBinaryInPath('ddev');
    }

    /**
     * Get the absolute path to the PHP binary.
     *
     * @return string|null Path to php binary, or null if not found
     */
    public function getPhpBinaryPath(): ?string {
        // PHP_BINARY is most reliable
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            return PHP_BINARY;
        }

        return $this->findBinaryInPath('php');
    }

    /**
     * Find a binary in the system PATH.
     */
    private function findBinaryInPath(string $binary): ?string {
        $command = PHP_OS_FAMILY === 'Windows'
            ? "where {$binary} 2>NUL"
            : "which {$binary} 2>/dev/null";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode === 0 && (isset($output[0]) && ($output[0] !== '' && $output[0] !== '0'))) {
            return trim($output[0]);
        }

        return null;
    }
}
