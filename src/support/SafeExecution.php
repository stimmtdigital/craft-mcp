<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use Throwable;

/**
 * Helper for safe tool execution with detailed error messages.
 *
 * Wraps execution and converts exceptions to ToolCallException
 * so the MCP SDK shows the actual error message to users.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SafeExecution {
    use ExceptionFormatterTrait;

    /**
     * Execute a callable and convert any exceptions to ToolCallException.
     * $context is optional and only needed by callers that want a detected
     * config refresh to notify resource subscribers (see ConfigFreshness);
     * every other call site keeps working unchanged without it.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws ToolCallException
     */
    public static function run(callable $callback, ?RequestContext $context = null): mixed {
        ConfigFreshness::ensure($context);

        try {
            return $callback();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ToolCallException(
                self::formatErrorMessage($e),
                (int) $e->getCode(),
                $e,
            );
        }
    }
}
