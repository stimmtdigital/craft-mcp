<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;

/**
 * The page window every listing tool takes: how many rows, and how many to
 * skip before the first one.
 *
 * WHY this is a class rather than a check in each tool: the three out-of-range
 * spellings each behaved differently underneath, and none of them was what the
 * caller asked for. A negative limit dropped the LIMIT clause and returned the
 * entire table into the model's context (defeating paginationLimit and
 * flooding the very budget the parameter exists to protect), 0 returned
 * nothing, a negative offset was ignored, and where the value is interpolated
 * into SQL (run_query, get_queue_jobs) it reached the database as a syntax
 * error naming the whole statement. list_entries had learned all of that; the
 * eleven other tools taking the same two parameters had not, because the rule
 * lived inside one of them.
 *
 * WHY refused rather than clamped: the paginated responses echo limit and
 * offset back, so a value quietly corrected reads as a value that was
 * honoured.
 *
 * The bounds are published as schema minimums too, off these same constants,
 * which is what an agent reads before it calls and what the SDK enforces on
 * the wire. This runs anyway, because the range is each method's own
 * invariant rather than something it may only hold when a validating
 * transport happens to be in front of it.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Window {
    /** A page of no rows is a question about a total, which the count tools answer. */
    public const int MIN_LIMIT = 1;

    public const int MIN_OFFSET = 0;

    public const string LIMIT_DESCRIPTION = 'How many rows to return. 1 or greater.';

    public const string OFFSET_DESCRIPTION = 'How many rows to skip before the first returned row. 0 or greater.';

    public static function assert(int $limit, int $offset = self::MIN_OFFSET): void {
        if ($limit < self::MIN_LIMIT) {
            throw new ToolCallException(
                'limit must be ' . self::MIN_LIMIT . " or greater, got {$limit}.",
            );
        }

        if ($offset < self::MIN_OFFSET) {
            throw new ToolCallException(
                'offset must be ' . self::MIN_OFFSET . " or greater, got {$offset}",
            );
        }
    }
}
