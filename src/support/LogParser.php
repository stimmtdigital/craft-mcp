<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Parser for Craft CMS log files.
 *
 * Handles log discovery, parsing, and multi-line stack trace extraction.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class LogParser {
    /**
     * Log line format: 2026-01-03 04:01:45 [web.INFO] [category] message
     */
    private const string LOG_PATTERN = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^.\]]+)\.(\w+)\] \[([^\]]*)\] (.*)$/';

    /**
     * Stack trace frame: #0 /path/to/file.php(123): Class->method()
     */
    private const string STACK_FRAME_PATTERN = '/^#(\d+)\s+(.+?)\((\d+)\):\s*(.*)$/';

    /**
     * Maximum files to process to avoid performance issues.
     */
    private const int MAX_FILES = 5;

    public function __construct(
        private readonly string $logPath,
    ) {
    }

    /**
     * Discover all log files recursively.
     *
     * @param string|null $source Filter by source prefix (e.g., "web", "console", "myplugin")
     * @return string[] Sorted list of log file paths (today's logs first, then by mtime)
     */
    public function discoverLogFiles(?string $source = null): array {
        if (!is_dir($this->logPath)) {
            return [];
        }

        $files = $this->findLogFilesRecursively();

        if ($source !== null) {
            $files = $this->filterBySource($files, $source);
        }

        return $this->sortByRelevance($files);
    }

    /**
     * Parse a log file into entries with multi-line support.
     *
     * @param string $filepath Path to the log file
     * @param string|null $levelFilter Filter by log level
     * @param string|null $pattern Filter by message content (case-insensitive)
     * @param int $maxLines Maximum lines to read from file
     * @return LogEntry[]
     */
    public function parseFile(
        string $filepath,
        ?string $levelFilter = null,
        ?string $pattern = null,
        int $maxLines = 100,
    ): array {
        if (!file_exists($filepath)) {
            return [];
        }

        $lines = FileHelper::tail($filepath, $maxLines);
        $filename = $this->getRelativePath($filepath);

        $entries = $this->parseLines($lines, $filename);

        return $this->filterEntries($entries, $levelFilter, $pattern);
    }

    /**
     * Find all .log files recursively in the log directory.
     *
     * @return string[]
     */
    private function findLogFilesRecursively(): array {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->logPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'log') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Filter files by source prefix.
     *
     * @param string[] $files
     * @return string[]
     */
    private function filterBySource(array $files, string $source): array {
        return array_values(array_filter(
            $files,
            static function (string $file) use ($source): bool {
                $basename = basename($file, '.log');

                return str_starts_with($basename, $source);
            },
        ));
    }

    /**
     * Sort files by relevance: today's logs first, then by modification time.
     *
     * @param string[] $files
     * @return string[]
     */
    private function sortByRelevance(array $files): array {
        $today = date('Y-m-d');

        usort($files, static function (string $a, string $b) use ($today): int {
            $aIsToday = str_contains($a, $today);
            $bIsToday = str_contains($b, $today);

            if ($aIsToday !== $bIsToday) {
                return $aIsToday ? -1 : 1;
            }

            return filemtime($b) <=> filemtime($a);
        });

        return array_slice($files, 0, self::MAX_FILES);
    }

    /**
     * Parse lines into LogEntry objects with multi-line support.
     *
     * @param string[] $lines
     * @return LogEntry[]
     */
    private function parseLines(array $lines, string $filename): array {
        $rawEntries = $this->groupLinesIntoRawEntries($lines);

        return array_map(
            fn (array $raw): LogEntry => $this->createLogEntry($raw, $filename),
            $rawEntries,
        );
    }

    /**
     * Group raw lines into entries (header line + continuation lines).
     *
     * @param string[] $lines
     * @return array<array{header: string, continuation: string[]}>
     */
    private function groupLinesIntoRawEntries(array $lines): array {
        $entries = [];
        $currentHeader = null;
        $continuation = [];

        foreach ($lines as $line) {
            $isNewEntry = $this->isLogEntryStart($line);
            $isNonEmptyLine = trim($line) !== '';

            // New entry found - save previous and start fresh
            if ($isNewEntry) {
                $entries = $this->appendEntryIfValid($entries, $currentHeader, $continuation);
                $currentHeader = $line;
                $continuation = [];

                continue;
            }

            // Accumulate non-empty continuation lines
            if ($currentHeader !== null && $isNonEmptyLine) {
                $continuation[] = $line;
            }
        }

        // Append final entry
        return $this->appendEntryIfValid($entries, $currentHeader, $continuation);
    }

    /**
     * Append entry to list if header is valid.
     *
     * @param array<array{header: string, continuation: string[]}> $entries
     * @param string[] $continuation
     * @return array<array{header: string, continuation: string[]}>
     */
    private function appendEntryIfValid(array $entries, ?string $header, array $continuation): array {
        if ($header === null) {
            return $entries;
        }

        $entries[] = ['header' => $header, 'continuation' => $continuation];

        return $entries;
    }

    /**
     * Create LogEntry from raw grouped data.
     *
     * @param array{header: string, continuation: string[]} $raw
     */
    private function createLogEntry(array $raw, string $filename): LogEntry {
        $parsed = $this->parseLogLine($raw['header']);

        // Fallback for unparseable lines (shouldn't happen, but be defensive)
        $parsed ??= [
            'timestamp' => '',
            'channel' => 'unknown',
            'level' => 'info',
            'category' => '',
            'message' => $raw['header'],
        ];

        return $this->finalizeEntry($parsed, $raw['continuation'], $filename);
    }

    /**
     * Check if a line starts a new log entry.
     */
    private function isLogEntryStart(string $line): bool {
        return preg_match(self::LOG_PATTERN, $line) === 1;
    }

    /**
     * Parse a single log line into components.
     *
     * @return array{timestamp: string, channel: string, level: string, category: string, message: string}|null
     */
    private function parseLogLine(string $line): ?array {
        if (!preg_match(self::LOG_PATTERN, $line, $matches)) {
            return null;
        }

        return [
            'timestamp' => $matches[1],
            'channel' => $matches[2],
            'level' => strtolower($matches[3]),
            'category' => $matches[4],
            'message' => trim($matches[5]),
        ];
    }

    /**
     * Finalize an entry by creating LogEntry with parsed stack trace.
     *
     * @param array{timestamp: string, channel: string, level: string, category: string, message: string} $data
     * @param string[] $continuationLines
     */
    private function finalizeEntry(array $data, array $continuationLines, string $filename): LogEntry {
        $stackTrace = $this->parseStackTrace($continuationLines);

        // If we have continuation lines but they're not a stack trace, append to message
        $message = $data['message'];
        if ($stackTrace === [] && $continuationLines !== []) {
            $message .= "\n" . implode("\n", $continuationLines);
        }

        return new LogEntry(
            timestamp: $data['timestamp'],
            channel: $data['channel'],
            level: $data['level'],
            category: $data['category'],
            message: $message,
            file: $filename,
            stackTrace: $stackTrace !== [] ? $stackTrace : null,
        );
    }

    /**
     * Parse stack trace lines into StackFrame objects.
     *
     * @param string[] $lines
     * @return StackFrame[]
     */
    private function parseStackTrace(array $lines): array {
        $frames = [];

        foreach ($lines as $line) {
            if (preg_match(self::STACK_FRAME_PATTERN, $line, $matches)) {
                $frames[] = StackFrame::fromMatch($matches);
            }
        }

        return $frames;
    }

    /**
     * Filter entries by level and/or pattern.
     *
     * @param LogEntry[] $entries
     * @return LogEntry[]
     */
    private function filterEntries(array $entries, ?string $levelFilter, ?string $pattern): array {
        return array_values(array_filter(
            $entries,
            static function (LogEntry $entry) use ($levelFilter, $pattern): bool {
                if ($levelFilter !== null && !$entry->matchesLevel($levelFilter)) {
                    return false;
                }

                if ($pattern !== null && !$entry->matchesPattern($pattern)) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * Get relative path from log directory for cleaner output.
     */
    private function getRelativePath(string $filepath): string {
        if (str_starts_with($filepath, $this->logPath)) {
            return ltrim(substr($filepath, strlen($this->logPath)), '/\\');
        }

        return basename($filepath);
    }
}
