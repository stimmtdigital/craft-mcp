<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer;

use craft\helpers\FileHelper;
use craft\helpers\Json;
use JsonException;

/**
 * Handles reading, merging, and writing MCP configuration files.
 */
final class ConfigWriter {
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Write a server configuration to a config file.
     *
     * If the file already exists, merges the new server into the existing config.
     * Other servers in the file are preserved.
     *
     * @param string $path Absolute path to the config file
     * @param string $serverName Name of the server (key in mcpServers)
     * @param array $serverConfig Server configuration array
     * @return WriteResult Result of the write operation
     * @throws JsonException If existing config cannot be parsed
     */
    public function write(string $path, string $serverName, array $serverConfig): WriteResult {
        $existing = $this->loadExisting($path);
        $serverExisted = $existing !== null && isset($existing['mcpServers'][$serverName]);
        $merged = $this->merge($existing, $serverName, $serverConfig);

        $this->save($path, $merged);

        if ($existing === null) {
            return WriteResult::created($path);
        }

        if ($serverExisted) {
            return WriteResult::overwritten($path);
        }

        return WriteResult::added($path);
    }

    /**
     * Check if a server already exists in the config file.
     *
     * @param string $path Absolute path to the config file
     * @param string $serverName Name of the server to check
     * @return bool True if the server exists
     */
    public function serverExists(string $path, string $serverName): bool {
        $existing = $this->loadExisting($path);

        return $existing !== null && isset($existing['mcpServers'][$serverName]);
    }

    /**
     * Load existing configuration from a file.
     *
     * @param string $path Absolute path to the config file
     * @return array|null Parsed config array, or null if file doesn't exist
     * @throws JsonException If file exists but contains invalid JSON
     */
    private function loadExisting(string $path): ?array {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $content = trim($content);

        if ($content === '') {
            return null;
        }

        return Json::decode($content);
    }

    /**
     * Merge a server configuration into an existing config.
     *
     * @param array|null $existing Existing config (or null for new file)
     * @param string $serverName Server name
     * @param array $serverConfig Server configuration
     * @return array Merged configuration
     */
    private function merge(?array $existing, string $serverName, array $serverConfig): array {
        $config = $existing ?? [];

        if (!isset($config['mcpServers'])) {
            $config['mcpServers'] = [];
        }

        $config['mcpServers'][$serverName] = $serverConfig;

        return $config;
    }

    /**
     * Save configuration to a file.
     *
     * Creates parent directories if they don't exist.
     *
     * @param string $path Absolute path to the config file
     * @param array $config Configuration array to save
     */
    private function save(string $path, array $config): void {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            FileHelper::createDirectory($dir);
        }

        $json = json_encode($config, self::JSON_FLAGS);

        file_put_contents($path, $json . "\n");
    }
}
