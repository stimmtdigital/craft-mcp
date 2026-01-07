<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\clients;

use Override;

/**
 * MCP client configuration for Claude Desktop.
 *
 * Generates configuration in the OS-specific Claude Desktop config location.
 * Requires absolute paths and a `cwd` field since the config is global.
 */
final class ClaudeDesktopClient extends AbstractMcpClient {
    public function getId(): string {
        return 'claude-desktop';
    }

    public function getName(): string {
        return 'Claude Desktop';
    }

    public function getDescription(): string {
        return $this->getConfigLocation();
    }

    public function getConfigPath(): string {
        return match (PHP_OS_FAMILY) {
            'Darwin' => $this->getMacOsConfigPath(),
            'Windows' => $this->getWindowsConfigPath(),
            default => $this->getLinuxConfigPath(),
        };
    }

    #[Override]
    public function requiresAbsolutePaths(): bool {
        return true;
    }

    /**
     * Get a human-readable config location for display.
     */
    private function getConfigLocation(): string {
        return match (PHP_OS_FAMILY) {
            'Darwin' => '~/Library/Application Support/Claude/',
            'Windows' => '%APPDATA%/Claude/',
            default => '~/.config/Claude/',
        };
    }

    private function getMacOsConfigPath(): string {
        $home = getenv('HOME');

        return $home . '/Library/Application Support/Claude/claude_desktop_config.json';
    }

    private function getWindowsConfigPath(): string {
        $appData = getenv('APPDATA');

        return $appData . '/Claude/claude_desktop_config.json';
    }

    private function getLinuxConfigPath(): string {
        $configHome = getenv('XDG_CONFIG_HOME');

        if ($configHome === false || $configHome === '') {
            $configHome = getenv('HOME') . '/.config';
        }

        return $configHome . '/Claude/claude_desktop_config.json';
    }
}
