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
     * Success response with data.
     */
    public static function success(array $data = []): array {
        return ['success' => true, ...$data];
    }

    /**
     * Error response.
     */
    public static function error(string $message, array $context = []): array {
        return ['success' => false, 'error' => $message, ...$context];
    }

    /**
     * Not found response for single-item lookups.
     */
    public static function notFound(string $type, string|int|null $identifier = null): array {
        $message = $identifier !== null
            ? "{$type} '{$identifier}' not found"
            : "{$type} not found";

        return ['found' => false, 'error' => $message];
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
