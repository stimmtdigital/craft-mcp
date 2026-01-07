<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\clients;

/**
 * MCP client configuration for Claude Code.
 *
 * Generates `.mcp.json` in the project root.
 */
final class ClaudeCodeClient extends AbstractMcpClient {
    public function getId(): string {
        return 'claude-code';
    }

    public function getName(): string {
        return 'Claude Code';
    }

    public function getDescription(): string {
        return '.mcp.json in project root';
    }

    public function getConfigPath(): string {
        return $this->projectRoot . '/.mcp.json';
    }
}
