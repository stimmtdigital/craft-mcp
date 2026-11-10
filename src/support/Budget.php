<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\Mcp;

/**
 * Refuses a response larger than `maxResponseBytes`, instead of flooding the
 * model's context with it.
 *
 * WHY each list-shaped tool calls this itself: Craft's MCP server has no hook
 * around a tool call, so the check that 1.x ran once for every tool now runs
 * where a response can grow without bound (log lines, SQL rows, schemas).
 */
final class Budget {
    /**
     * @template T of array<array-key, mixed>
     * @param T $response
     * @return T
     */
    public static function assert(array $response, ?int $maxBytes = null): array {
        $max = $maxBytes ?? Mcp::settings()->maxResponseBytes;
        $size = strlen((string) json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        if ($size > $max) {
            throw new ToolCallException(
                "The response is {$size} bytes, over this install's limit of {$max} (maxResponseBytes)."
                . ' Ask for less: a lower limit, a narrower filter, or one table or item at a time.',
            );
        }

        return $response;
    }
}
