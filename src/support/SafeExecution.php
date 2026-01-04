<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

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
    /**
     * Execute a callable and convert any exceptions to ToolCallException.
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
            // Already a ToolCallException, rethrow as-is
            throw $e;
        } catch (Throwable $e) {
            // Convert to ToolCallException with detailed message
            throw new ToolCallException(
                self::formatErrorMessage($e),
                (int) $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Format a detailed error message from an exception.
     */
    private static function formatErrorMessage(Throwable $e): string {
        $message = $e->getMessage();
        $class = self::getShortClassName($e);
        $location = self::getShortLocation($e);

        return "{$class}: {$message} ({$location})";
    }

    /**
     * Get short class name without namespace.
     */
    private static function getShortClassName(Throwable $e): string {
        $class = $e::class;
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }

    /**
     * Get short file location (filename:line).
     */
    private static function getShortLocation(Throwable $e): string {
        $file = $e->getFile();
        $line = $e->getLine();

        $filename = basename($file);

        return "{$filename}:{$line}";
    }
}
