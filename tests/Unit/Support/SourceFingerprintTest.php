<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\SourceFingerprint;

function sampleTree(string $root, array $files): string {
    mkdir($root . '/tools', 0o777, true);

    foreach ($files as $name => $contents) {
        file_put_contents($root . '/' . $name, $contents);
    }

    return $root;
}

function dropTree(string $root): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($root);
}

beforeEach(function () {
    $this->root = sampleTree(sys_get_temp_dir() . '/mcp-fingerprint-' . uniqid(), [
        'Mcp.php' => '<?php // plugin',
        'tools/EntryTools.php' => '<?php // entries',
        'tools/README.md' => 'not php',
    ]);
});

afterEach(function () {
    dropTree($this->root);
});

describe('SourceFingerprint', function () {
    it('is stable for an unchanged tree', function () {
        $fingerprint = new SourceFingerprint();

        expect($fingerprint->of($this->root))->toBe($fingerprint->of($this->root));
    });

    it('changes when a file is edited', function () {
        $before = (new SourceFingerprint())->of($this->root);

        file_put_contents($this->root . '/tools/EntryTools.php', '<?php // entries, edited');

        expect((new SourceFingerprint())->of($this->root))->not->toBe($before);
    });

    it('changes when a file is only touched, as a branch switch does', function () {
        $before = (new SourceFingerprint())->of($this->root);

        touch($this->root . '/tools/EntryTools.php', time() + 60);

        expect((new SourceFingerprint())->of($this->root))->not->toBe($before);
    });

    it('changes when a tool class is added', function () {
        $before = (new SourceFingerprint())->of($this->root);

        file_put_contents($this->root . '/tools/AssetTools.php', '<?php // assets');

        expect((new SourceFingerprint())->of($this->root))->not->toBe($before);
    });

    it('changes when a tool class is removed', function () {
        $before = (new SourceFingerprint())->of($this->root);

        unlink($this->root . '/tools/EntryTools.php');

        expect((new SourceFingerprint())->of($this->root))->not->toBe($before);
    });

    it('ignores files that are not php', function () {
        $before = (new SourceFingerprint())->of($this->root);

        file_put_contents($this->root . '/tools/README.md', 'rewritten documentation');

        expect((new SourceFingerprint())->of($this->root))->toBe($before);
    });

    it('does not depend on where the tree lives, so a moved install keeps its cache', function () {
        $copy = $this->root . '-elsewhere';
        exec('cp -Rp ' . escapeshellarg($this->root) . ' ' . escapeshellarg($copy));

        $fingerprint = new SourceFingerprint();
        $digest = $fingerprint->of($copy);
        dropTree($copy);

        expect($digest)->toBe($fingerprint->of($this->root));
    });

    it('returns a digest rather than failing on a missing directory', function () {
        expect((new SourceFingerprint())->of($this->root . '/nope'))
            ->toBeString()
            ->not->toBe('');
    });

    it('produces a digest safe to use inside a cache key', function () {
        expect((new SourceFingerprint())->of($this->root))->toMatch('/^[0-9a-f]+$/');
    });
});
