<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\installer\clients\ClaudeCode;
use stimmt\craft\Mcp\installer\clients\Codex;
use stimmt\craft\Mcp\installer\ConfigWriter;
use stimmt\craft\Mcp\installer\EnvironmentDetector;
use stimmt\craft\Mcp\installer\TomlConfigWriter;

describe('Codex client', function () {
    beforeEach(function () {
        $this->codex = new Codex('/srv/site/backend', '/srv/site', new EnvironmentDetector('/srv/site/backend'));
    });

    it('writes the project-scoped Codex config as TOML', function () {
        expect($this->codex->getId())->toBe('codex')
            ->and($this->codex->getConfigPath())->toBe('/srv/site/.codex/config.toml')
            ->and($this->codex->configWriter())->toBeInstanceOf(TomlConfigWriter::class);
    });

    // Codex starts servers from wherever it was launched, and the craft
    // script in args is relative, so the config has to carry the project as cwd.
    it('pins the working directory, since the craft path is relative', function () {
        $config = $this->codex->generateServerConfig(EnvironmentDetector::DDEV, 'editor@example.com', '/srv/site', 'backend');

        expect($this->codex->requiresAbsolutePaths())->toBeTrue()
            ->and($config['cwd'])->toBe('/srv/site')
            ->and($config['args'])->toBe(['exec', 'php', 'backend/craft', 'mcp/serve', '--user=editor@example.com']);
    });

    // Since 2.0 the plugin runs no server of its own: every client starts
    // Craft's, acting as one user, and the plugin's tools come with it.
    it('starts Craft\'s own stdio server as the given user, natively too', function () {
        $claudeCode = new ClaudeCode('/srv/site', '/srv/site', new EnvironmentDetector('/srv/site'));
        $config = $claudeCode->generateServerConfig(EnvironmentDetector::NATIVE, '7');

        expect($config['command'])->toBe('php')
            ->and($config['args'])->toBe(['craft', 'mcp/serve', '--user=7']);
    });

    it('leaves the existing JSON clients on the JSON writer', function () {
        $claudeCode = new ClaudeCode('/srv/site', '/srv/site', new EnvironmentDetector('/srv/site'));

        expect($claudeCode->configWriter())->toBeInstanceOf(ConfigWriter::class);
    });
});
