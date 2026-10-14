<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\installer\ConfigWriter;

/**
 * The JSON every existing client gets (.mcp.json, .cursor/mcp.json, Claude
 * Desktop's config). Pinned exactly, so giving the wizard a second format
 * cannot change a byte of what the first one writes.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/craft-mcp-json-' . uniqid();
    mkdir($this->dir, 0755, true);
    $this->path = $this->dir . '/.mcp.json';
});

afterEach(function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->dir);
});

describe('ConfigWriter', function () {
    it('writes the same pretty JSON it always has', function () {
        (new ConfigWriter())->write($this->path, 'craft-cms', ['command' => 'ddev', 'args' => ['exec', 'php', 'vendor/stimmt/craft-mcp/bin/mcp-server']]);

        expect(file_get_contents($this->path))->toBe(<<<'JSON'
            {
                "mcpServers": {
                    "craft-cms": {
                        "command": "ddev",
                        "args": [
                            "exec",
                            "php",
                            "vendor/stimmt/craft-mcp/bin/mcp-server"
                        ]
                    }
                }
            }

            JSON);
    });

    it('keeps other servers and keys when adding one', function () {
        file_put_contents($this->path, '{"theme":"dark","mcpServers":{"other":{"command":"npx"}}}');

        $result = (new ConfigWriter())->write($this->path, 'craft-cms', ['command' => 'php', 'args' => []]);
        $config = json_decode((string) file_get_contents($this->path), true);

        expect($result->created)->toBeFalse()
            ->and($config['theme'])->toBe('dark')
            ->and($config['mcpServers'])->toHaveKeys(['other', 'craft-cms']);
    });
});
