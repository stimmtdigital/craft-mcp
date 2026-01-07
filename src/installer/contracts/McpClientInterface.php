<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\contracts;

/**
 * Contract for MCP client configuration generators.
 *
 * Each implementation knows how to generate configuration for a specific
 * MCP client (Claude Code, Cursor, Claude Desktop, etc.).
 */
interface McpClientInterface {
    /**
     * Get the unique identifier for this client.
     *
     * @return string e.g., 'claude-code', 'cursor', 'claude-desktop'
     */
    public function getId(): string;

    /**
     * Get the human-readable name for this client.
     *
     * @return string e.g., 'Claude Code', 'Cursor', 'Claude Desktop'
     */
    public function getName(): string;

    /**
     * Get a description of where the config file is stored.
     *
     * @return string e.g., '.mcp.json in project root'
     */
    public function getDescription(): string;

    /**
     * Get the full path to the configuration file.
     *
     * @return string Absolute path to the config file
     */
    public function getConfigPath(): string;

    /**
     * Whether this client requires absolute paths in its configuration.
     *
     * Some clients (like Claude Desktop) need absolute paths for the command
     * and a `cwd` field, while others (like Claude Code) work with relative paths.
     *
     * @return bool True if absolute paths are required
     */
    public function requiresAbsolutePaths(): bool;

    /**
     * Generate the server configuration array for this client.
     *
     * @param string $environment The environment type ('ddev' or 'native')
     * @param string|null $projectPath Absolute project path (required if requiresAbsolutePaths() is true)
     * @return array{command: string, args: string[], cwd?: string}
     */
    public function generateServerConfig(string $environment, ?string $projectPath = null): array;
}
