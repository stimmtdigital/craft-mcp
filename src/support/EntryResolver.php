<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\elements\Entry;
use craft\models\Site;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\elements\Lookup;

/**
 * Resolving an entry id for a tool that intends to CHANGE something, in
 * HandleResolver's shape: what the id names, or a refusal that says what is
 * wrong and which id to ask for instead.
 *
 * WHY the two halves travel together: a revision is only diagnosable while it
 * is still findable. Looking a write id up with a query that excludes
 * revisions turns "3306 is frozen history of 3285" into "Entry 3306 not
 * found", and get_entry answering the same id with a full payload in the same
 * session is what sends an agent hunting for an element that is right there.
 * Every tool that resolved its own id had to remember to admit revisions
 * first and reject them second; publish_entry and delete_entry did neither,
 * which is exactly the fifth call site a per-tool guard gets forgotten at.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class EntryResolver {
    /**
     * The element an id names, once it is known to be one a write can land
     * on. Canonical entries and drafts pass (a Matrix block is an entry too,
     * and so is its draft); a revision is refused by name.
     *
     * @param string $tool the tool to name in the refusal, which is the call
     *                     the agent should make next with the canonical id
     */
    public static function writable(int $id, ?Site $site, string $tool): Entry {
        $entry = Lookup::inAnyState($id, $site) ?? throw self::missing($id);
        self::assertWritable($entry, $tool);

        return $entry;
    }

    /**
     * A revision is frozen history. Writing to one saved an element nobody
     * reads and reported success under the CANONICAL id, which was never
     * touched, so the agent believed an edit that does not exist. get_entry
     * still reads a revision, which is the whole point of keeping history;
     * only the write refuses, and it names the id that works.
     */
    public static function assertWritable(Entry $entry, string $tool): void {
        if (!$entry->getIsRevision()) {
            return;
        }

        $canonicalId = (int) $entry->getCanonicalId();

        throw new ToolCallException(
            "Entry {$entry->id} is a revision of entry {$canonicalId}; revisions are frozen history and cannot be written to."
            . " Call {$tool} with id {$canonicalId} instead.",
        );
    }

    /**
     * SiteResolver's house style: say what was wrong AND where the right
     * value comes from.
     */
    public static function missing(int $id): ToolCallException {
        return new ToolCallException("Entry {$id} not found. Use list_entries to find an entry id.");
    }
}
