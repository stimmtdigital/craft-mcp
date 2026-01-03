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
     * Read the last N lines from a file efficiently.
     *
     * @param string $filepath Path to the file
     * @param int $lines Number of lines to read
     * @return array<string> Lines from the file (oldest first)
     */
    public static function tail(string $filepath, int $lines = 50): array {
        if (!file_exists($filepath)) {
            return [];
        }

        $handle = fopen($filepath, 'r');
        if (!$handle) {
            return [];
        }

        $result = [];
        $buffer = '';
        $lineCount = 0;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);

        while ($pos > 0 && $lineCount < $lines) {
            $pos--;
            fseek($handle, $pos);
            $char = fgetc($handle);

            if ($char === "\n") {
                if ($buffer !== '') {
                    array_unshift($result, $buffer);
                    $lineCount++;
                    $buffer = '';
                }
            } else {
                $buffer = $char . $buffer;
            }
        }

        if ($buffer !== '' && $lineCount < $lines) {
            array_unshift($result, $buffer);
        }

        fclose($handle);

        return $result;
    }
}
