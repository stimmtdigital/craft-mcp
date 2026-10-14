<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer\clients;

use Override;
use stimmt\craft\Mcp\installer\contracts\ConfigFormat;
use stimmt\craft\Mcp\installer\TomlConfigWriter;

/**
 * MCP client configuration for OpenAI Codex (CLI and IDE extension).
 *
 * Writes `.codex/config.toml` in the project root, which Codex reads for
 * projects the user has marked as trusted. Needs an absolute `cwd`: Codex
 * starts servers from wherever it was launched, and the binary path in
 * `args` is relative to the project.
 */
final class Codex extends Base {
    public function getId(): string {
        return 'codex';
    }

    public function getName(): string {
        return 'Codex';
    }

    public function getDescription(): string {
        return '.codex/config.toml, trusted projects';
    }

    public function getConfigPath(): string {
        return $this->configRoot . '/.codex/config.toml';
    }

    #[Override]
    public function requiresAbsolutePaths(): bool {
        return true;
    }

    #[Override]
    public function configWriter(): ConfigFormat {
        return new TomlConfigWriter();
    }
}
