<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\logging;

/**
 * One log query: what to keep, how much of it, and which log files to look in.
 *
 * WHY an object rather than five parameters threaded through the parser: the
 * knobs belong to a single question, and they had already reached the limit
 * where the next one would have been positional noise at the call site.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Search {
    /**
     * @param int $limit How many entries to keep, which bounds the results and not the search
     * @param string|null $level Exact log level to keep, case-insensitive
     * @param string|null $pattern Case-insensitive substring the entry must contain
     * @param string|null $source Log file prefix (e.g., "web", "console", "myplugin")
     * @param bool $headlineOnly Match the pattern against the logged line alone, ignoring continuation lines
     */
    public function __construct(
        public int $limit = 50,
        public ?string $level = null,
        public ?string $pattern = null,
        public ?string $source = null,
        public bool $headlineOnly = false,
    ) {
    }

    public function matches(Entry $entry): bool {
        if ($this->level !== null && !$entry->matchesLevel($this->level)) {
            return false;
        }

        if ($this->pattern === null) {
            return true;
        }

        return $entry->matchesPattern($this->pattern, $this->headlineOnly);
    }
}
