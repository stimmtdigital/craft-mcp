<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

use craft\elements\Entry;
use craft\models\Site;

/**
 * Finding one entry by id, in the element states the caller is willing to
 * accept.
 *
 * WHY this is not left to each tool: every tool that takes an entry id has to
 * disable the status filter and decide whether a draft or a revision counts as
 * a match. Two tools had grown their own private `find()` doing the same
 * things with different answers, which is how a fourth tool ends up inheriting
 * whichever copy it was pasted from.
 *
 * The states are named methods rather than flags because they are the whole
 * decision the caller is making, and `find($id, $site, true, false)` says
 * nothing at the call site about what those booleans admit.
 *
 * WHY it takes a resolved Site rather than a handle: this module is kept free
 * of the MCP SDK, and refusing an unknown handle means raising a tool error.
 * Taking the model instead puts that refusal at the tool boundary where it
 * belongs, and makes it a type error to reach a query with a handle nobody
 * checked.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Lookup {
    /**
     * Published entries only. What a caller wants when a draft would be the
     * wrong thing to act on.
     */
    public static function canonical(int $id, ?Site $site): ?Entry {
        return self::query($id, $site, drafts: false, revisions: false);
    }

    /**
     * Canonical entries and drafts. Writes land as drafts by default, so a
     * tool acting on "the thing that was just written" needs both.
     */
    public static function withDrafts(int $id, ?Site $site): ?Entry {
        return self::query($id, $site, drafts: true, revisions: false);
    }

    /**
     * Every state, revisions included. Revisions are read-only history, so a
     * caller admitting them here is usually doing it to REJECT them with a
     * message naming the canonical entry, which a blind miss could not give.
     */
    public static function inAnyState(int $id, ?Site $site): ?Entry {
        return self::query($id, $site, drafts: true, revisions: true);
    }

    private static function query(int $id, ?Site $site, bool $drafts, bool $revisions): ?Entry {
        $query = Entry::find()->id($id)->status(null);

        if ($drafts) {
            $query->drafts(null);
        }

        if ($revisions) {
            $query->revisions(null);
        }

        if ($site !== null) {
            $query->site($site);
        }

        return $query->one();
    }
}
