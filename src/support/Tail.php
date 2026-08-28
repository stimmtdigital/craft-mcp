<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Generator;

/**
 * The end of a file, read backwards rather than by walking the whole thing:
 * either the last N lines in one go, or block by block for a caller that does
 * not know up front how far back it has to look.
 *
 * WHY it is not called FileHelper any more: Craft ships craft\helpers\FileHelper,
 * and the two had to coexist by aliasing one of them at every call site that
 * touched both. "Helper" also said nothing; this class does one thing, and now
 * its name is that thing.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Tail {
    /**
     * Bytes pulled in per backwards step, which is also roughly how big a
     * yielded block is. Only one chunk plus the lines it holds is ever in
     * memory, so a caller can walk back through a multi-megabyte log without
     * the file ever being in memory.
     */
    private const int CHUNK_SIZE = 65536;

    /**
     * Read the last N lines from a file.
     *
     * @param string $filepath Path to the file
     * @param int $lines Number of lines to read
     * @return string[] Lines from the file (oldest first)
     */
    public static function of(string $filepath, int $lines = 50): array {
        $result = [];

        foreach (self::blocks($filepath) as $block) {
            $result = [...$block, ...$result];

            if (count($result) >= $lines) {
                break;
            }
        }

        return array_slice($result, -$lines);
    }

    /**
     * Walk a file backwards, yielding blocks of whole lines.
     *
     * The newest block comes first, and lines inside a block keep file order
     * (oldest first), so a caller can parse a block exactly the way it would
     * parse the file and stop as soon as it has seen enough.
     *
     * WHY two caps rather than one: line length differs by an order of
     * magnitude between logs. Fifty thousand lines of a Craft web log is a
     * couple of megabytes, the same count of a JSON-per-line server log is
     * twenty, so a line cap alone bounds the first and not the second.
     *
     * @param string $filepath Path to the file
     * @param int $maxLines Stop once this many lines have been yielded
     * @param int $maxBytes Never read further back than this many bytes
     * @return Generator<int, string[]>
     */
    public static function blocks(
        string $filepath,
        int $maxLines = PHP_INT_MAX,
        int $maxBytes = PHP_INT_MAX,
    ): Generator {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return;
        }

        $filesize = filesize($filepath);

        if ($filesize === false || $filesize === 0) {
            return;
        }

        $handle = fopen($filepath, 'r');

        if ($handle === false) {
            return;
        }

        try {
            yield from self::walkBackwards($handle, $filesize, $maxLines, $maxBytes);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Yield blocks of whole lines, newest block first.
     *
     * @param resource $handle File handle
     * @param int $filesize Size of file in bytes
     * @param int $maxLines Stop once this many lines have been yielded
     * @param int $maxBytes Never read further back than this many bytes
     * @return Generator<int, string[]>
     */
    private static function walkBackwards($handle, int $filesize, int $maxLines, int $maxBytes): Generator {
        $position = self::lastLineEnd($handle, $filesize);
        $floor = max(0, $position - $maxBytes);
        $buffer = '';
        $yielded = 0;

        while ($position > $floor) {
            $size = min(self::CHUNK_SIZE, $position - $floor);
            $position -= $size;

            fseek($handle, $position);
            $chunk = fread($handle, $size);

            if ($chunk === false) {
                return;
            }

            [$lines, $buffer] = self::splitOffPartialLine($chunk . $buffer, $position === 0);

            if ($lines === []) {
                continue;
            }

            $yielded += count($lines);

            yield array_map(static fn (string $line): string => rtrim($line, "\r"), $lines);

            if ($yielded >= $maxLines) {
                return;
            }
        }
    }

    /**
     * Where the last line ends, which is the file size minus the single
     * trailing newline a log file normally ends with. Without this the walk
     * would report that newline as an extra empty line.
     *
     * @param resource $handle File handle
     */
    private static function lastLineEnd($handle, int $filesize): int {
        fseek($handle, $filesize - 1);

        return fread($handle, 1) === "\n" ? $filesize - 1 : $filesize;
    }

    /**
     * Split a buffer into the whole lines it ends with and the leading bytes
     * that still belong to a line whose start has not been read yet. Those
     * bytes go back into the buffer for the next step, unless the read has
     * reached the start of the file and they are a whole line already.
     *
     * @return array{0: string[], 1: string} Lines (oldest first) and the leftover
     */
    private static function splitOffPartialLine(string $buffer, bool $atFileStart): array {
        $parts = explode("\n", $buffer);

        if ($atFileStart) {
            return [$parts, ''];
        }

        $partial = array_shift($parts);

        return [$parts, (string) $partial];
    }
}
