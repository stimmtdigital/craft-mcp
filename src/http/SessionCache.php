<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use Symfony\Component\Uid\Uuid;

/**
 * One request's view of the mcp_sessions rows it touched.
 *
 * WHY: the SDK saves the session at four points per JSON-RPC message, and every
 * one of them builds a fresh Session through SessionManager::createWithId(). A
 * fresh Session re-reads its row on first access, so a single message paid for
 * one SELECT per save point plus one more for the exists() check that precedes
 * them, and then upserted the same row four times.
 *
 * Within one request that row cannot change under us: one HTTP request is one
 * logical session, and every write in it goes through this process. So the first
 * read is the only one worth making, and a repeated write of a payload we
 * already stored is worth nothing at all.
 *
 * Deliberately request-scoped rather than shared: DbSessionStore is constructed
 * per request by the controller, which is what makes "written at least once"
 * below mean "written at least once in this request".
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SessionCache {
    /**
     * Payloads as read() would return them, false included: "there is no live
     * row" is just as worth remembering as a payload, and it is what the store
     * contract uses to say it.
     *
     * @var array<string, string|false>
     */
    private array $payloads = [];

    /**
     * Ids this instance has already written.
     *
     * The first write of a request must reach the table even when the payload is
     * byte-identical to what is in it, because dateUpdated is what keeps the
     * session inside its TTL window; a long-lived connection whose payload never
     * changes would otherwise expire mid-conversation. Every write after that
     * one has nothing left to contribute.
     *
     * @var array<string, true>
     */
    private array $written = [];

    /**
     * @param callable(): (string|false) $load
     */
    public function remember(Uuid $id, callable $load): string|false {
        return $this->payloads[$id->toRfc4122()] ??= $load();
    }

    /**
     * True when this payload is already in the table and was put there by this
     * instance, so writing it again would only rewrite dateUpdated.
     */
    public function isStored(Uuid $id, string $data): bool {
        $key = $id->toRfc4122();

        return isset($this->written[$key]) && ($this->payloads[$key] ?? null) === $data;
    }

    public function store(Uuid $id, string $data): void {
        $key = $id->toRfc4122();
        $this->payloads[$key] = $data;
        $this->written[$key] = true;
    }

    /**
     * Drops what we know about these ids, so the next read goes to the table and
     * the next write is a first write again. Called for rows this process
     * deleted: a skipped write after a delete would lose the session.
     */
    public function forget(Uuid ...$ids): void {
        foreach ($ids as $id) {
            $key = $id->toRfc4122();
            unset($this->payloads[$key], $this->written[$key]);
        }
    }
}
