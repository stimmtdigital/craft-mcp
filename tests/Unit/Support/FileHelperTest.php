<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\FileHelper;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/craft-mcp-tests';
    if (!is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
});

afterEach(function () {
    // Clean up temp files
    $files = glob($this->tempDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

describe('FileHelper::tail()', function () {
    it('returns empty array for non-existent file', function () {
        $result = FileHelper::tail('/non/existent/file.txt');

        expect($result)->toBe([]);
    });

    it('reads last N lines from file', function () {
        $filepath = $this->tempDir . '/test.txt';
        file_put_contents($filepath, "line1\nline2\nline3\nline4\nline5\n");

        $result = FileHelper::tail($filepath, 3);

        expect($result)
            ->toHaveCount(3)
            ->toBe(['line3', 'line4', 'line5']);
    });

    it('handles file with fewer lines than requested', function () {
        $filepath = $this->tempDir . '/small.txt';
        file_put_contents($filepath, "line1\nline2\n");

        $result = FileHelper::tail($filepath, 10);

        expect($result)
            ->toHaveCount(2)
            ->toBe(['line1', 'line2']);
    });

    it('handles empty file', function () {
        $filepath = $this->tempDir . '/empty.txt';
        file_put_contents($filepath, '');

        $result = FileHelper::tail($filepath);

        expect($result)->toBe([]);
    });

    it('handles file without trailing newline', function () {
        $filepath = $this->tempDir . '/no-trailing.txt';
        file_put_contents($filepath, "line1\nline2\nline3"); // No trailing newline

        $result = FileHelper::tail($filepath, 2);

        expect($result)
            ->toHaveCount(2)
            ->toBe(['line2', 'line3']);
    });

    it('handles single line file', function () {
        $filepath = $this->tempDir . '/single.txt';
        file_put_contents($filepath, 'single line');

        $result = FileHelper::tail($filepath, 5);

        expect($result)
            ->toHaveCount(1)
            ->toBe(['single line']);
    });

    it('returns lines in correct order (oldest first)', function () {
        $filepath = $this->tempDir . '/ordered.txt';
        file_put_contents($filepath, "first\nsecond\nthird\nfourth\nfifth\n");

        $result = FileHelper::tail($filepath, 5);

        expect($result[0])->toBe('first')
            ->and($result[4])->toBe('fifth');
    });

    it('uses default of 50 lines', function () {
        $filepath = $this->tempDir . '/many.txt';
        $lines = [];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = "line{$i}";
        }
        file_put_contents($filepath, implode("\n", $lines) . "\n");

        $result = FileHelper::tail($filepath);

        expect($result)
            ->toHaveCount(50)
            ->and($result[0])->toBe('line51')
            ->and($result[49])->toBe('line100');
    });

    it('handles lines with special characters', function () {
        $filepath = $this->tempDir . '/special.txt';
        file_put_contents($filepath, "line with spaces\nline\twith\ttabs\nline: with: colons\n");

        $result = FileHelper::tail($filepath, 3);

        expect($result)
            ->toContain('line with spaces')
            ->toContain("line\twith\ttabs")
            ->toContain('line: with: colons');
    });

    it('handles unicode content', function () {
        $filepath = $this->tempDir . '/unicode.txt';
        file_put_contents($filepath, "Hello 世界\nПривет мир\n日本語\n");

        $result = FileHelper::tail($filepath, 3);

        expect($result)
            ->toContain('Hello 世界')
            ->toContain('Привет мир')
            ->toContain('日本語');
    });
});
