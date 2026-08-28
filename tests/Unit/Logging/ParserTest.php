<?php

declare(strict_types=1);

use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\Parser;
use stimmt\craft\Mcp\logging\Search;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/craft-mcp-log-tests';
    if (!is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    // Clean up temp files recursively
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tempDir);
});

describe('Parser::discoverLogFiles()', function () {
    it('returns empty array for non-existent directory', function () {
        $parser = new Parser('/non/existent/path');

        expect($parser->discoverLogFiles())->toBe([]);
    });

    it('finds log files in directory', function () {
        file_put_contents($this->tempDir . '/web.log', 'test');
        file_put_contents($this->tempDir . '/console.log', 'test');

        $parser = new Parser($this->tempDir);
        $files = $parser->discoverLogFiles();

        expect($files)->toHaveCount(2);
    });

    it('finds log files recursively', function () {
        mkdir($this->tempDir . '/plugins', 0755, true);
        file_put_contents($this->tempDir . '/web.log', 'test');
        file_put_contents($this->tempDir . '/plugins/myplugin.log', 'test');

        $parser = new Parser($this->tempDir);
        $files = $parser->discoverLogFiles();

        expect($files)->toHaveCount(2);
    });

    it('filters by source prefix', function () {
        file_put_contents($this->tempDir . '/web.log', 'test');
        file_put_contents($this->tempDir . '/web-2026-01-07.log', 'test');
        file_put_contents($this->tempDir . '/console.log', 'test');

        $parser = new Parser($this->tempDir);
        $files = $parser->discoverLogFiles('web');

        expect($files)->toHaveCount(2);
        foreach ($files as $file) {
            expect(basename($file))->toStartWith('web');
        }
    });

    it('limits to 5 files', function () {
        for ($i = 1; $i <= 10; $i++) {
            file_put_contents($this->tempDir . "/log{$i}.log", 'test');
        }

        $parser = new Parser($this->tempDir);
        $files = $parser->discoverLogFiles();

        expect($files)->toHaveCount(5);
    });
});

describe('Parser::scanFile()', function () {
    it('returns empty array for non-existent file', function () {
        $parser = new Parser($this->tempDir);

        expect($parser->scanFile('/non/existent/file.log', new Search()))->toBe([]);
    });

    it('parses simple log entries', function () {
        $logContent = <<<'LOG'
2026-01-07 10:30:00 [web.INFO] [application] Application started
2026-01-07 10:30:01 [web.ERROR] [application] Something failed
LOG;
        file_put_contents($this->tempDir . '/web.log', $logContent);

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search());

        expect($entries)->toHaveCount(2)
            ->and($entries[0])->toBeInstanceOf(Entry::class)
            ->and($entries[0]->level)->toBe('info')
            ->and($entries[1]->level)->toBe('error');
    });

    it('filters by level', function () {
        $logContent = <<<'LOG'
2026-01-07 10:30:00 [web.INFO] [application] Info message
2026-01-07 10:30:01 [web.ERROR] [application] Error message
2026-01-07 10:30:02 [web.WARNING] [application] Warning message
LOG;
        file_put_contents($this->tempDir . '/web.log', $logContent);

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search(level: 'error'));

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->level)->toBe('error');
    });

    it('filters by pattern', function () {
        $logContent = <<<'LOG'
2026-01-07 10:30:00 [web.INFO] [application] Database connected
2026-01-07 10:30:01 [web.ERROR] [application] Network error
2026-01-07 10:30:02 [web.ERROR] [application] Database error
LOG;
        file_put_contents($this->tempDir . '/web.log', $logContent);

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search(pattern: 'database'));

        expect($entries)->toHaveCount(2);
    });

    it('parses multi-line messages', function () {
        $logContent = <<<'LOG'
2026-01-07 10:30:00 [web.ERROR] [application] Error occurred
Additional context line 1
Additional context line 2
2026-01-07 10:30:01 [web.INFO] [application] Next entry
LOG;
        file_put_contents($this->tempDir . '/web.log', $logContent);

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search());

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->message)->toContain('Additional context line 1');
    });

    it('parses stack traces', function () {
        $logContent = <<<'LOG'
2026-01-07 10:30:00 [web.ERROR] [application] Exception thrown
#0 /var/www/html/vendor/file.php(123): SomeClass->method()
#1 /var/www/html/src/app.php(456): OtherClass->call()
2026-01-07 10:30:01 [web.INFO] [application] Recovery
LOG;
        file_put_contents($this->tempDir . '/web.log', $logContent);

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search());

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->hasStackTrace())->toBeTrue()
            ->and($entries[0]->stackTrace)->toHaveCount(2)
            ->and($entries[0]->stackTrace[0]->index)->toBe(0)
            ->and($entries[0]->stackTrace[0]->line)->toBe(123);
    });

    it('returns relative file path', function () {
        $logContent = '2026-01-07 10:30:00 [web.INFO] [application] Test';
        mkdir($this->tempDir . '/subdir', 0755, true);
        file_put_contents($this->tempDir . '/subdir/plugin.log', $logContent);

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/subdir/plugin.log', new Search());

        expect($entries[0]->file)->toBe('subdir/plugin.log');
    });
});

describe('Parser::scanFile() search depth', function () {
    it('finds a match far from the end of the file', function () {
        // The defect this pins: limit used to double as the number of lines
        // read, so an error sitting behind a wall of routine traffic came back
        // as "no errors found" with total confidence.
        $lines = array_fill(0, 3000, '2026-01-07 10:31:00 [web.INFO] [application] Routine request');
        array_unshift($lines, '2026-01-07 10:30:00 [web.ERROR] [application] Buried failure');
        file_put_contents($this->tempDir . '/web.log', implode("\n", $lines) . "\n");

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search(limit: 1, level: 'error'));

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->message)->toBe('Buried failure');
    });

    it('terminates when the filter matches nothing', function () {
        $lines = array_fill(0, 3000, '2026-01-07 10:31:00 [web.INFO] [application] Routine request');
        file_put_contents($this->tempDir . '/web.log', implode("\n", $lines) . "\n");

        $parser = new Parser($this->tempDir);

        expect($parser->scanFile($this->tempDir . '/web.log', new Search(limit: 5, level: 'error')))->toBe([]);
    });

    it('keeps the newest matches when the file holds more than the limit', function () {
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $minute = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $lines[] = "2026-01-07 10:{$minute}:00 [web.ERROR] [application] Failure {$i}";
        }
        file_put_contents($this->tempDir . '/web.log', implode("\n", $lines) . "\n");

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search(limit: 3, level: 'error'));

        expect($entries)->toHaveCount(3)
            ->and($entries[0]->message)->toBe('Failure 38')
            ->and($entries[2]->message)->toBe('Failure 40');
    });

    it('stops at the byte cap instead of reading the whole file', function () {
        // One error, then more than MAX_SCAN_BYTES of traffic on top of it.
        $filler = str_pad('2026-01-07 10:31:00 [web.INFO] [application] ', 999, 'x');
        $content = "2026-01-07 10:30:00 [web.ERROR] [application] Beyond the cap\n"
            . str_repeat($filler . "\n", intdiv(Parser::MAX_SCAN_BYTES, 1000) + 500);
        file_put_contents($this->tempDir . '/web.log', $content);

        $parser = new Parser($this->tempDir);

        expect($parser->scanFile($this->tempDir . '/web.log', new Search(limit: 1, level: 'error')))->toBe([]);
    });

    it('keeps a stack trace whole across every block boundary', function () {
        // Large enough to span several backwards blocks. A trace split by a
        // boundary has to be reunited with the header it belongs to, so every
        // entry in the file must come back with both of its frames.
        $entry = "2026-01-07 10:30:00 [web.ERROR] [application] Exception thrown\n"
            . "#0 /var/www/html/vendor/file.php(123): SomeClass->method()\n"
            . "#1 /var/www/html/src/app.php(456): OtherClass->call()\n";
        file_put_contents($this->tempDir . '/web.log', str_repeat($entry, 2000));

        $parser = new Parser($this->tempDir);
        $entries = $parser->scanFile($this->tempDir . '/web.log', new Search(limit: 2000));

        $withBothFrames = array_filter($entries, static fn (Entry $e): bool => count($e->stackTrace ?? []) === 2);

        expect($entries)->toHaveCount(2000)
            ->and($withBothFrames)->toHaveCount(2000);
    });
});

describe('Parser::newest()', function () {
    it('merges files and returns the newest matches first', function () {
        file_put_contents(
            $this->tempDir . '/web.log',
            "2026-01-07 10:30:00 [web.ERROR] [application] Web failure\n",
        );
        file_put_contents(
            $this->tempDir . '/console.log',
            "2026-01-07 11:00:00 [console.ERROR] [application] Console failure\n",
        );

        $parser = new Parser($this->tempDir);
        $entries = $parser->newest(new Search(limit: 5, level: 'error'));

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->message)->toBe('Console failure')
            ->and($entries[1]->message)->toBe('Web failure');
    });

    it('honours the source filter', function () {
        file_put_contents(
            $this->tempDir . '/web.log',
            "2026-01-07 10:30:00 [web.ERROR] [application] Web failure\n",
        );
        file_put_contents(
            $this->tempDir . '/console.log',
            "2026-01-07 11:00:00 [console.ERROR] [application] Console failure\n",
        );

        $parser = new Parser($this->tempDir);
        $entries = $parser->newest(new Search(limit: 5, level: 'error', source: 'console'));

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->message)->toBe('Console failure');
    });

    it('reports every file it searches to the callback', function () {
        file_put_contents($this->tempDir . '/web.log', "2026-01-07 10:30:00 [web.INFO] [application] A\n");
        file_put_contents($this->tempDir . '/console.log', "2026-01-07 11:00:00 [console.INFO] [application] B\n");

        $seen = [];
        $parser = new Parser($this->tempDir);
        $parser->newest(
            new Search(limit: 5),
            function (string $file, int $position, int $total) use (&$seen): void {
                $seen[] = basename($file) . " {$position}/{$total}";
            },
        );

        expect($seen)->toHaveCount(2)
            ->and($seen[1])->toEndWith('2/2');
    });

    it('bounds the results across files, not per file', function () {
        file_put_contents(
            $this->tempDir . '/web.log',
            "2026-01-07 10:30:00 [web.ERROR] [application] Web failure\n",
        );
        file_put_contents(
            $this->tempDir . '/console.log',
            "2026-01-07 11:00:00 [console.ERROR] [application] Console failure\n",
        );

        $parser = new Parser($this->tempDir);

        expect($parser->newest(new Search(limit: 1, level: 'error')))->toHaveCount(1);
    });
});

describe('Parser::scanDepth()', function () {
    it('names both caps so a tool can quote the depth it searched', function () {
        expect(Parser::scanDepth())
            ->toContain((string) Parser::MAX_SCAN_LINES)
            ->toContain('lines')
            ->toContain('MB per file');
    });
});

describe('Parser::newest() pattern scope', function () {
    $log = <<<'LOG'
2026-01-07 10:30:00 [web.INFO] [application] Request context:
$_SERVER = [
    'body' => '{"name":"get_deprecations","arguments":{"limit":5}}'
]
2026-01-07 10:31:00 [web.WARNING] [yii\base\ErrorException] Deprecated: strlen(): Passing null is deprecated
LOG;

    it('searches continuation lines by default, which is what makes a trace searchable', function () use ($log) {
        file_put_contents($this->tempDir . '/web.log', $log . "\n");

        $parser = new Parser($this->tempDir);
        $entries = $parser->newest(new Search(limit: 5, pattern: 'deprecat'));

        expect($entries)->toHaveCount(2);
    });

    it('ignores continuation lines when the search asks about the entry itself', function () use ($log) {
        // The request-context dump quotes this tool's own name back at it, so
        // an unscoped sweep for deprecations reported the requests that asked
        // for deprecations as deprecations.
        file_put_contents($this->tempDir . '/web.log', $log . "\n");

        $parser = new Parser($this->tempDir);
        $entries = $parser->newest(new Search(limit: 5, pattern: 'deprecat', headlineOnly: true));

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->level)->toBe('warning')
            ->and($entries[0]->message)->toContain('Passing null is deprecated');
    });

    it('keeps filling the limit past a headline that does not match', function () {
        // The scoping happens inside the walk, so entries rejected on scope do
        // not eat into the limit and end the search early.
        $lines = array_fill(0, 500, "2026-01-07 10:30:00 [web.INFO] [application] Request context:\nbody: deprecations");
        array_unshift($lines, '2026-01-07 10:29:00 [web.WARNING] [application] Deprecated: something old');
        file_put_contents($this->tempDir . '/web.log', implode("\n", $lines) . "\n");

        $parser = new Parser($this->tempDir);
        $entries = $parser->newest(new Search(limit: 5, pattern: 'deprecat', headlineOnly: true));

        expect($entries)->toHaveCount(1)
            ->and($entries[0]->message)->toContain('something old');
    });
});
