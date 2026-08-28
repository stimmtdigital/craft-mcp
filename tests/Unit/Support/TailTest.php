<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Tail;

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

describe('Tail::of()', function () {
    it('returns empty array for non-existent file', function () {
        $result = Tail::of('/non/existent/file.txt');

        expect($result)->toBe([]);
    });

    it('reads last N lines from file', function () {
        $filepath = $this->tempDir . '/test.txt';
        file_put_contents($filepath, "line1\nline2\nline3\nline4\nline5\n");

        $result = Tail::of($filepath, 3);

        expect($result)
            ->toHaveCount(3)
            ->toBe(['line3', 'line4', 'line5']);
    });

    it('handles file with fewer lines than requested', function () {
        $filepath = $this->tempDir . '/small.txt';
        file_put_contents($filepath, "line1\nline2\n");

        $result = Tail::of($filepath, 10);

        expect($result)
            ->toHaveCount(2)
            ->toBe(['line1', 'line2']);
    });

    it('handles empty file', function () {
        $filepath = $this->tempDir . '/empty.txt';
        file_put_contents($filepath, '');

        $result = Tail::of($filepath);

        expect($result)->toBe([]);
    });

    it('handles file without trailing newline', function () {
        $filepath = $this->tempDir . '/no-trailing.txt';
        file_put_contents($filepath, "line1\nline2\nline3"); // No trailing newline

        $result = Tail::of($filepath, 2);

        expect($result)
            ->toHaveCount(2)
            ->toBe(['line2', 'line3']);
    });

    it('handles single line file', function () {
        $filepath = $this->tempDir . '/single.txt';
        file_put_contents($filepath, 'single line');

        $result = Tail::of($filepath, 5);

        expect($result)
            ->toHaveCount(1)
            ->toBe(['single line']);
    });

    it('returns lines in correct order (oldest first)', function () {
        $filepath = $this->tempDir . '/ordered.txt';
        file_put_contents($filepath, "first\nsecond\nthird\nfourth\nfifth\n");

        $result = Tail::of($filepath, 5);

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

        $result = Tail::of($filepath);

        expect($result)
            ->toHaveCount(50)
            ->and($result[0])->toBe('line51')
            ->and($result[49])->toBe('line100');
    });

    it('handles lines with special characters', function () {
        $filepath = $this->tempDir . '/special.txt';
        file_put_contents($filepath, "line with spaces\nline\twith\ttabs\nline: with: colons\n");

        $result = Tail::of($filepath, 3);

        expect($result)
            ->toContain('line with spaces')
            ->toContain("line\twith\ttabs")
            ->toContain('line: with: colons');
    });

    it('handles unicode content', function () {
        $filepath = $this->tempDir . '/unicode.txt';
        file_put_contents($filepath, "Hello 世界\nПривет мир\n日本語\n");

        $result = Tail::of($filepath, 3);

        expect($result)
            ->toContain('Hello 世界')
            ->toContain('Привет мир')
            ->toContain('日本語');
    });
});

describe('Tail::blocks()', function () {
    it('yields nothing for a non-existent file', function () {
        expect(iterator_to_array(Tail::blocks('/non/existent/file.txt')))->toBe([]);
    });

    it('yields nothing for an empty file', function () {
        $filepath = $this->tempDir . '/empty-blocks.txt';
        file_put_contents($filepath, '');

        expect(iterator_to_array(Tail::blocks($filepath)))->toBe([]);
    });

    it('yields a small file as one block in file order', function () {
        $filepath = $this->tempDir . '/small-blocks.txt';
        file_put_contents($filepath, "line1\nline2\nline3\n");

        $blocks = iterator_to_array(Tail::blocks($filepath));

        expect($blocks)->toHaveCount(1)
            ->and($blocks[0])->toBe(['line1', 'line2', 'line3']);
    });

    it('walks a large file backwards, newest block first', function () {
        $filepath = $this->tempDir . '/large-blocks.txt';
        $lines = [];
        for ($i = 1; $i <= 8000; $i++) {
            $lines[] = str_pad("line{$i}", 40, '.');
        }
        file_put_contents($filepath, implode("\n", $lines) . "\n");

        $blocks = iterator_to_array(Tail::blocks($filepath));
        $flattened = array_merge(...array_reverse($blocks));

        expect(count($blocks))->toBeGreaterThan(1)
            ->and($blocks[0][array_key_last($blocks[0])])->toBe(str_pad('line8000', 40, '.'))
            ->and($flattened)->toHaveCount(8000)
            ->and($flattened[0])->toBe(str_pad('line1', 40, '.'));
    });

    it('stops once the line cap is reached', function () {
        $filepath = $this->tempDir . '/line-capped.txt';
        $lines = [];
        for ($i = 1; $i <= 8000; $i++) {
            $lines[] = str_pad("line{$i}", 40, '.');
        }
        file_put_contents($filepath, implode("\n", $lines) . "\n");

        $uncapped = iterator_to_array(Tail::blocks($filepath));
        $capped = iterator_to_array(Tail::blocks($filepath, maxLines: 1));

        expect($capped)->toHaveCount(1)
            ->and(count($uncapped))->toBeGreaterThan(1);
    });

    it('never reads further back than the byte cap', function () {
        $filepath = $this->tempDir . '/byte-capped.txt';
        $lines = [];
        for ($i = 1; $i <= 8000; $i++) {
            $lines[] = str_pad("line{$i}", 40, '.');
        }
        file_put_contents($filepath, implode("\n", $lines) . "\n");

        $blocks = iterator_to_array(Tail::blocks($filepath, maxBytes: 100000));
        $flattened = array_merge(...array_reverse($blocks));

        // 100000 bytes of 41-byte lines is a little under 2440 of them, and the
        // line the cap lands in the middle of is dropped rather than truncated.
        expect(count($flattened))->toBeLessThan(2440)
            ->and($flattened[count($flattened) - 1])->toBe(str_pad('line8000', 40, '.'));
    });
});
