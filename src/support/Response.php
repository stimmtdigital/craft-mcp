<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

/**
 * Standardized response helpers for MCP tools.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Response {
    /**
     * The key a tool payload states its own outcome under. One constant, so
     * the producer (success/failure) and the reader (isFailure, which the
     * Presenter uses to raise isError on the wire) can never disagree about
     * what a failed call looks like.
     */
    private const string OUTCOME = 'success';

    /**
     * Success response with data.
     */
    public static function success(array $data = []): array {
        return [self::OUTCOME => true, ...$data];
    }

    /**
     * Failure response with data: a call the tool itself refused.
     *
     * The transport is what a client reads an outcome from, so this is not
     * decoration. Presenter::handle() turns a payload this produced into a
     * CallToolResult with isError set, which is the flag that tells a model
     * to self-correct rather than to trust the call and move on.
     */
    public static function failure(array $data = []): array {
        return [self::OUTCOME => false, ...$data];
    }

    /**
     * Whether a tool return states its own failure.
     *
     * Deliberately identity-compared against false: a payload with no outcome
     * key at all (every read tool) is not a failure, and neither is one that
     * merely carries a falsy value under some other key.
     */
    public static function isFailure(mixed $payload): bool {
        return is_array($payload) && ($payload[self::OUTCOME] ?? null) === false;
    }

    /**
     * Found response for single-item lookups.
     */
    public static function found(string $key, mixed $data): array {
        return ['found' => true, $key => $data];
    }

    /**
     * List response with count and items.
     */
    public static function list(string $key, array $items, array $meta = []): array {
        return [
            'count' => count($items),
            ...$meta,
            $key => $items,
        ];
    }

    /**
     * Paginated list response: one page of a countable result set, which the
     * caller walks by asking again at offset + limit.
     */
    public static function paginated(string $key, array $items, int $total, int $limit, int $offset): array {
        return self::window($key, $items, $limit, $total, $offset);
    }

    /**
     * Capped list response: a tool that bounds its rows but has no offset to
     * page by, because the thing it reads is not an addressable result set (a
     * backward log scan, a queue snapshot, a statement the caller wrote).
     *
     * The total is optional because for several of those it is genuinely
     * unknowable without redoing the whole read, and a total nobody counted
     * is worse than none. That is also why it follows the limit here rather
     * than leading it as in paginated(): it is the argument a capped tool may
     * not have. The limit is nullable in turn for the one tool whose cap the
     * caller's own input can override, since naming a cap that was not applied
     * would be the same lie one level down.
     */
    public static function capped(string $key, array $items, ?int $limit, ?int $total = null, array $meta = []): array {
        return self::window($key, $items, $limit, $total, null, $meta);
    }

    /**
     * The envelope every list tool answers with, built in one place because
     * the question is the same one everywhere: did I see everything, and if
     * not, how do I ask for the rest?
     *
     * WHY count alone is not an answer: a page of exactly `limit` rows and a
     * complete set of exactly `limit` rows look identical, and the caller
     * cannot even tell which cap produced it when the limit came from a
     * default it never sent. So the cap is always echoed.
     *
     * WHY offset is present or absent rather than always zero: it mirrors the
     * tool's own input. A tool that takes an offset echoes one and can be
     * paged; a tool that does not takes no offset back, and inventing one
     * would advertise paging that does not exist.
     *
     * WHY hasMore is null rather than inferred when the total is unknown:
     * "count reached the limit" is not the same fact as "more rows exist",
     * and stating the second when only the first is known is exactly the
     * wrong answer to trust. Null says the tool cannot tell, with count and
     * limit sitting right there for a caller that wants the inference.
     */
    private static function window(string $key, array $items, ?int $limit, ?int $total, ?int $offset, array $meta = []): array {
        $count = count($items);

        return [
            'count' => $count,
            'total' => $total,
            'limit' => $limit,
            ...($offset === null ? [] : ['offset' => $offset]),
            'hasMore' => $total === null ? null : ($offset ?? 0) + $count < $total,
            ...$meta,
            $key => $items,
        ];
    }
}
