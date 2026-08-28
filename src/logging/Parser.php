<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\logging;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use stimmt\craft\Mcp\support\Tail;

/**
 * Parser for Craft CMS log files.
 *
 * Handles log discovery, searching, parsing, and multi-line stack trace
 * extraction.
 *
 * WHY searching lives here rather than in the tools: a caller asking for the
 * newest N errors is asking about results, not about how many lines to read.
 * Reading a window and filtering it afterwards answers a different question,
 * and answers it wrong: on a busy install the newest error can be thousands of
 * lines from the end of the file, so a small limit used to return "no errors"
 * with total confidence. The search walks backwards until it has N matches or
 * it hits a cap, and it does that in one place so both read_logs and
 * get_deprecations get the same answer.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Parser {
    /**
     * How far back into a single file a search will look before giving up.
     *
     * WHY a cap at all: a filter that matches nothing would otherwise read
     * every byte of a rotated log that can be tens of megabytes. WHY these two
     * numbers together: fifty thousand lines is deep enough to cross a day of
     * request logging on a busy install, while four megabytes keeps a log with
     * very long lines (a JSON payload per line) from turning that same depth
     * into a twenty megabyte read.
     */
    public const int MAX_SCAN_LINES = 50000;

    public const int MAX_SCAN_BYTES = 4194304;

    /**
     * How many continuation lines are carried across a block boundary to be
     * reunited with the header they belong to. Bounded because a file whose
     * lines never parse as a header would otherwise accumulate all of them.
     */
    private const int MAX_CARRIED_LINES = 200;

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
        private string $logPath,
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
     * How deep a search looks, in words, for the tools that have to explain a
     * "nothing found" answer honestly.
     */
    public static function scanDepth(): string {
        return self::MAX_SCAN_LINES . ' lines or ' . intdiv(self::MAX_SCAN_BYTES, 1024 * 1024) . ' MB per file';
    }

    /**
     * The newest entries matching a search, across every discovered log file.
     *
     * @param callable(string, int, int): void|null $onFile Called per file with its path, position and the total
     * @return Entry[] Newest first
     */
    public function newest(Search $search, ?callable $onFile = null): array {
        $files = $this->discoverLogFiles($search->source);
        $total = count($files);
        $entries = [];

        foreach ($files as $index => $file) {
            if ($onFile !== null) {
                $onFile($file, $index + 1, $total);
            }

            $entries = array_merge($entries, $this->scanFile($file, $search));
        }

        usort($entries, static fn (Entry $a, Entry $b): int => $b->timestamp <=> $a->timestamp);

        return array_slice($entries, 0, $search->limit);
    }

    /**
     * The newest entries in one log file that match the search.
     *
     * Walks the file backwards a block at a time and stops as soon as the
     * limit is met, so a match far from the end of the file is still found. A
     * search that matches nothing walks back until one of the scan caps is
     * reached, and no further.
     *
     * @return Entry[] Oldest first
     */
    public function scanFile(string $filepath, Search $search): array {
        $filename = $this->getRelativePath($filepath);
        $found = [];
        $carried = [];

        foreach (Tail::blocks($filepath, self::MAX_SCAN_LINES, self::MAX_SCAN_BYTES) as $block) {
            // Lines carried back from the newer block continue the last entry
            // of this one, so they are parsed here, with the header they
            // belong to, rather than dropped at the boundary.
            $lines = [...$block, ...$carried];
            $header = $this->firstHeaderIndex($lines);
            $carried = array_slice($lines, 0, min($header, self::MAX_CARRIED_LINES));

            $entries = $this->parseLines(array_slice($lines, $header), $filename);
            $found = [...array_filter($entries, $search->matches(...)), ...$found];

            if (count($found) >= $search->limit) {
                break;
            }
        }

        return array_slice($found, -$search->limit);
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
     * Parse lines into Entry objects with multi-line support.
     *
     * @param string[] $lines
     * @return Entry[]
     */
    private function parseLines(array $lines, string $filename): array {
        $rawEntries = $this->groupLinesIntoRawEntries($lines);

        return array_map(
            fn (array $raw): Entry => $this->createLogEntry($raw, $filename),
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
     * Create Entry from raw grouped data.
     *
     * @param array{header: string, continuation: string[]} $raw
     */
    private function createLogEntry(array $raw, string $filename): Entry {
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
     * Index of the first line that starts an entry, or the line count when a
     * block holds nothing but the tail of an entry that began earlier.
     *
     * @param string[] $lines
     */
    private function firstHeaderIndex(array $lines): int {
        foreach ($lines as $index => $line) {
            if ($this->isLogEntryStart($line)) {
                return $index;
            }
        }

        return count($lines);
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
     * Finalize an entry by creating Entry with parsed stack trace.
     *
     * @param array{timestamp: string, channel: string, level: string, category: string, message: string} $data
     * @param string[] $continuationLines
     */
    private function finalizeEntry(array $data, array $continuationLines, string $filename): Entry {
        $stackTrace = $this->parseStackTrace($continuationLines);

        // If we have continuation lines but they're not a stack trace, append to message
        $message = $data['message'];
        if ($stackTrace === [] && $continuationLines !== []) {
            $message .= "\n" . implode("\n", $continuationLines);
        }

        return new Entry(
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
     * Get relative path from log directory for cleaner output.
     */
    private function getRelativePath(string $filepath): string {
        if (str_starts_with($filepath, $this->logPath)) {
            return ltrim(substr($filepath, strlen($this->logPath)), '/\\');
        }

        return basename($filepath);
    }
}
