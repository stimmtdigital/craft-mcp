<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\errors\StaleResourceException;
use Mcp\Exception\ToolCallException;
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
     *
     * The MCP server is a long-lived process that boots Craft once, so its
     * loaded project config can go stale when an external process (a CLI
     * migration, `project-config/apply`, or a control-panel edit) changes it.
     * Write-path tools then throw a {@see StaleResourceException}. When that
     * happens we re-read the project config from YAML and retry the operation
     * ONCE, so callers don't have to reload the server by hand.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws ToolCallException
     */
    public static function run(callable $callback): mixed {
        try {
            return $callback();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (StaleResourceException) {
            self::recoverFromStaleProjectConfig();

            return self::retry($callback);
        } catch (Throwable $e) {
            throw self::toToolCallException($e);
        }
    }

    /**
     * Best-effort recovery from a stale project config.
     *
     * If the reset itself fails we still fall through to the retry, so the
     * caller sees the operation's real outcome rather than a recovery-time
     * error.
     */
    private static function recoverFromStaleProjectConfig(): void {
        try {
            PluginReloader::resetProjectConfig();
        } catch (Throwable) {
            // Intentionally ignored — fall through to the single retry below.
        }
    }

    /**
     * Re-run the callback after recovering from a stale project config.
     *
     * Only one retry is attempted; a still-failing call (including a persistent
     * {@see StaleResourceException}) is surfaced as a ToolCallException.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws ToolCallException
     */
    private static function retry(callable $callback): mixed {
        try {
            return $callback();
        } catch (ToolCallException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw self::toToolCallException($e);
        }
    }

    private static function toToolCallException(Throwable $e): ToolCallException {
        return new ToolCallException(
            self::formatErrorMessage($e),
            (int) $e->getCode(),
            $e,
        );
    }
}
