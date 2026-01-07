<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\LogEntry;
use stimmt\craft\Mcp\support\LogParser;

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

describe('LogParser::discoverLogFiles()', function () {
    it('returns empty array for non-existent directory', function () {
        $parser = new LogParser('/non/existent/path');

        expect($parser->discoverLogFiles())->toBe([]);
    });

    it('finds log files in directory', function () {
        file_put_contents($this->tempDir . '/web.log', 'test');
        file_put_contents($this->tempDir . '/console.log', 'test');

        $parser = new LogParser($this->tempDir);
        $files = $parser->discoverLogFiles();

        expect($files)->toHaveCount(2);
    });

    it('finds log files recursively', function () {
        mkdir($this->tempDir . '/plugins', 0755, true);
        file_put_contents($this->tempDir . '/web.log', 'test');
        file_put_contents($this->tempDir . '/plugins/myplugin.log', 'test');

        $parser = new LogParser($this->tempDir);
        $files = $parser->discoverLogFiles();

        expect($files)->toHaveCount(2);
    });

    it('filters by source prefix', function () {
        file_put_contents($this->tempDir . '/web.log', 'test');
        file_put_contents($this->tempDir . '/web-2026-01-07.log', 'test');
        file_put_contents($this->tempDir . '/console.log', 'test');

        $parser = new LogParser($this->tempDir);
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

        $parser = new LogParser($this->tempDir);
        $files = $parser->discoverLogFiles();

        expect($files)->toHaveCount(5);
    });
});

describe('LogParser::parseFile()', function () {
    it('returns empty array for non-existent file', function () {
        $parser = new LogParser($this->tempDir);

        expect($parser->parseFile('/non/existent/file.log'))->toBe([]);
    });

    it('parses simple log entries', function () {
        $logContent = <<<'LOG'
2026-01-07 10:30:00 [web.INFO] [application] Application started
2026-01-07 10:30:01 [web.ERROR] [application] Something failed
LOG;
        file_put_contents($this->tempDir . '/web.log', $logContent);

        $parser = new LogParser($this->tempDir);
        $entries = $parser->parseFile($this->tempDir . '/web.log');

        expect($entries)->toHaveCount(2)
            ->and($entries[0])->toBeInstanceOf(LogEntry::class)
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

        $parser = new LogParser($this->tempDir);
        $entries = $parser->parseFile($this->tempDir . '/web.log', 'error');

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

        $parser = new LogParser($this->tempDir);
        $entries = $parser->parseFile($this->tempDir . '/web.log', pattern: 'database');

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

        $parser = new LogParser($this->tempDir);
        $entries = $parser->parseFile($this->tempDir . '/web.log');

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

        $parser = new LogParser($this->tempDir);
        $entries = $parser->parseFile($this->tempDir . '/web.log');

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

        $parser = new LogParser($this->tempDir);
        $entries = $parser->parseFile($this->tempDir . '/subdir/plugin.log');

        expect($entries[0]->file)->toBe('subdir/plugin.log');
    });
});
