<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\ResourceReadException;
use Throwable;

/**
 * Helper for safe resource execution with detailed error messages.
 *
 * Wraps execution and converts exceptions to ResourceReadException
 * so the MCP SDK shows the actual error message to users.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SafeResourceExecution {
    use ExceptionFormatterTrait;

    /**
     * Execute a callable and convert any exceptions to ResourceReadException.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws ResourceReadException
     */
    public static function run(callable $callback): mixed {
        try {
            return $callback();
        } catch (ResourceReadException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ResourceReadException(
                self::formatErrorMessage($e),
                (int) $e->getCode(),
                $e,
            );
        }
    }
}
