<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\installer\TomlConfigWriter;

/**
 * Codex reads `[mcp_servers.<name>]` from a TOML file that usually holds the
 * user's other settings too, and nothing here parses TOML. So the writer only
 * ever touches the lines of the one server table it owns, and the tests pin
 * that everything else survives byte for byte.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/craft-mcp-toml-' . uniqid();
    // The directory exists up front: creating it goes through Craft's
    // FileHelper, whose default mode needs a booted app the wizard always has.
    mkdir($this->dir . '/.codex', 0755, true);
    $this->path = $this->dir . '/.codex/config.toml';
});

afterEach(function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->dir);
});

$server = ['command' => 'ddev', 'args' => ['exec', 'php', 'vendor/stimmt/craft-mcp/bin/mcp-server'], 'cwd' => '/Users/me/site'];

describe('TomlConfigWriter', function () use ($server) {
    it('creates the file', function () use ($server) {
        $result = (new TomlConfigWriter())->write($this->path, 'craft-cms', $server);

        expect($result->created)->toBeTrue()
            ->and(file_get_contents($this->path))->toBe(<<<'TOML'
                [mcp_servers.craft-cms]
                command = "ddev"
                args = ["exec", "php", "vendor/stimmt/craft-mcp/bin/mcp-server"]
                cwd = "/Users/me/site"

                TOML);
    });

    it('appends to an existing file and leaves every other line alone', function () use ($server) {
        $existing = "model = \"gpt-5\"\n\n# keep me\n[mcp_servers.other]\ncommand = \"npx\"\n";
        file_put_contents($this->path, $existing);

        $result = (new TomlConfigWriter())->write($this->path, 'craft-cms', $server);

        expect($result->created)->toBeFalse()
            ->and($result->serverExisted)->toBeFalse()
            ->and(file_get_contents($this->path))->toStartWith($existing)
            ->and(file_get_contents($this->path))->toContain("\n[mcp_servers.craft-cms]\ncommand = \"ddev\"\n");
    });

    it('replaces its own table and that table\'s subtables, and nothing else', function () use ($server) {
        file_put_contents($this->path, <<<'TOML'
            model = "gpt-5"

            [mcp_servers.craft-cms]
            command = "php"
            args = ["old"]

            [mcp_servers.craft-cms.env]
            OLD = "1"

            [mcp_servers.craft-cms-staging]
            command = "keep"

            [profiles.fast]
            model = "o4-mini"

            TOML);

        $result = (new TomlConfigWriter())->write($this->path, 'craft-cms', $server);
        $content = (string) file_get_contents($this->path);

        expect($result->serverExisted)->toBeTrue()
            ->and($content)->not->toContain('OLD = "1"')
            ->and($content)->not->toContain('args = ["old"]')
            ->and($content)->toContain("[mcp_servers.craft-cms-staging]\ncommand = \"keep\"")
            ->and($content)->toContain("[profiles.fast]\nmodel = \"o4-mini\"")
            ->and($content)->toStartWith("model = \"gpt-5\"\n")
            ->and(substr_count($content, '[mcp_servers.craft-cms]'))->toBe(1);
    });

    it('finds an existing server whether its name is bare or quoted', function () {
        file_put_contents($this->path, "[mcp_servers.\"craft cms\"]\ncommand = \"php\"\n[mcp_servers.craft-cms]\n");
        $writer = new TomlConfigWriter();

        expect($writer->serverExists($this->path, 'craft cms'))->toBeTrue()
            ->and($writer->serverExists($this->path, 'craft-cms'))->toBeTrue()
            ->and($writer->serverExists($this->path, 'craft'))->toBeFalse()
            ->and($writer->serverExists($this->dir . '/missing.toml', 'craft-cms'))->toBeFalse();
    });

    it('quotes a server name TOML would not accept bare, and escapes string values', function () {
        (new TomlConfigWriter())->write($this->path, 'craft cms', ['command' => 'C:\\php\\php.exe', 'args' => ['say "hi"']]);

        expect(file_get_contents($this->path))->toBe("[mcp_servers.\"craft cms\"]\ncommand = \"C:\\\\php\\\\php.exe\"\nargs = [\"say \\\"hi\\\"\"]\n");
    });
});
