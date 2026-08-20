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
     * Paginated list response.
     */
    public static function paginated(string $key, array $items, int $total, int $limit, int $offset): array {
        return [
            'count' => count($items),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            $key => $items,
        ];
    }
}
