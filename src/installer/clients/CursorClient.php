<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\clients;

/**
 * MCP client configuration for Cursor.
 *
 * Generates `.cursor/mcp.json` in the project root.
 */
final class CursorClient extends AbstractMcpClient {
    public function getId(): string {
        return 'cursor';
    }

    public function getName(): string {
        return 'Cursor';
    }

    public function getDescription(): string {
        return '.cursor/mcp.json';
    }

    public function getConfigPath(): string {
        return $this->projectRoot . '/.cursor/mcp.json';
    }
}
