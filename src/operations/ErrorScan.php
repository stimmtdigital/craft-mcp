<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use DateTimeImmutable;
use DateTimeZone;
use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\Parser;
use stimmt\craft\Mcp\logging\Search;

/**
 * The error entries Craft logged over the last N hours, read with the same
 * bounded backwards search stimmt_logs_read uses.
 *
 * WHY a cap on entries as well as the parser's per-file depth: every entry is
 * held in memory with its trace, and outside dev mode a trace is one long line.
 * Without a cap a day of a recurring error on a busy site would be read whole.
 */
final readonly class ErrorScan {
    public const int MAX_ENTRIES = 500;

    /**
     * @param list<Entry> $entries Newest first
     * @param list<string> $files The log files read, relative to the log directory
     */
    public function __construct(
        public ErrorGroups $groups,
        public array $entries,
        public array $files,
        public string $since,
    ) {
    }

    /**
     * @param callable(string, int, int): void|null $onFile Progress callback, as Parser::newest() takes it
     */
    public static function run(string $logPath, string $timeZone, int $hours, ?string $source, ?callable $onFile = null): self {
        $zone = new DateTimeZone($timeZone);
        $since = (new DateTimeImmutable('now', $zone))->modify("-{$hours} hours")->format('Y-m-d H:i:s');
        $files = [];

        $entries = (new Parser($logPath))->newest(
            new Search(self::MAX_ENTRIES, 'error', null, $source),
            static function (string $file, int $position, int $total) use (&$files, $logPath, $onFile): void {
                $files[] = ltrim(substr($file, strlen($logPath)), '/\\');
                if ($onFile !== null) {
                    $onFile($file, $position, $total);
                }
            },
        );

        return new self(new ErrorGroups(array_values($entries), $since, $zone), array_values($entries), $files, $since);
    }

    /**
     * Whether the scan reached back past the start of the window. When the cap
     * filled up with entries that are all inside it, older ones in the window
     * were not read, and the counts are a floor.
     */
    public function complete(): bool {
        if (count($this->entries) < self::MAX_ENTRIES) {
            return true;
        }

        $oldest = $this->entries[array_key_last($this->entries)];

        return $oldest->timestamp < $this->since;
    }
}
