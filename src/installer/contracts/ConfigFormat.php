<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\contracts;

use stimmt\craft\Mcp\installer\WriteResult;

/**
 * Writes one MCP server entry into a client's config file, in whatever format
 * that client reads, leaving every other entry in the file alone.
 */
interface ConfigFormat {
    /**
     * @param array{command: string, args: string[], cwd?: string} $serverConfig
     */
    public function write(string $path, string $serverName, array $serverConfig): WriteResult;

    public function serverExists(string $path, string $serverName): bool;
}
