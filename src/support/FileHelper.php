<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

/**
 * File utility methods.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class FileHelper {
    /**
     * Default chunk size for reading files (8KB).
     */
    private const int CHUNK_SIZE = 8192;

    /**
     * Read the last N lines from a file efficiently.
     *
     * Uses chunk-based reading from the end of file for performance.
     * Handles files with or without trailing newlines correctly.
     *
     * @param string $filepath Path to the file
     * @param int $lines Number of lines to read
     * @return string[] Lines from the file (oldest first)
     */
    public static function tail(string $filepath, int $lines = 50): array {
        if (!file_exists($filepath) || !is_readable($filepath)) {
            return [];
        }

        $filesize = filesize($filepath);
        if ($filesize === false || $filesize === 0) {
            return [];
        }

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return [];
        }

        $result = self::readLinesFromEnd($handle, $filesize, $lines);
        fclose($handle);

        return $result;
    }

    /**
     * Read lines from end of file using chunk-based approach.
     *
     * @param resource $handle File handle
     * @param int $filesize Size of file in bytes
     * @param int $linesToRead Number of lines to read
     * @return string[]
     */
    private static function readLinesFromEnd($handle, int $filesize, int $linesToRead): array {
        $buffer = '';
        $position = $filesize;
        $foundLines = [];

        while ($position > 0 && count($foundLines) < $linesToRead + 1) {
            $chunkSize = min(self::CHUNK_SIZE, $position);
            $position -= $chunkSize;

            fseek($handle, $position);
            $chunk = fread($handle, $chunkSize);

            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            $foundLines = explode("\n", $buffer);
        }

        // Handle remaining content at start of file
        if ($position === 0 && $buffer !== '') {
            $foundLines = explode("\n", $buffer);
        }

        return self::extractLastLines($foundLines, $linesToRead);
    }

    /**
     * Extract the last N non-empty lines from the array.
     *
     * @param string[] $allLines All lines from buffer
     * @param int $count Number of lines to return
     * @return string[]
     */
    private static function extractLastLines(array $allLines, int $count): array {
        // Remove empty trailing element (from trailing newline)
        if ($allLines !== [] && $allLines[array_key_last($allLines)] === '') {
            array_pop($allLines);
        }

        // Take last N lines
        $result = array_slice($allLines, -$count);

        // Trim each line
        return array_map(static fn (string $line): string => rtrim($line, "\r"), $result);
    }
}
