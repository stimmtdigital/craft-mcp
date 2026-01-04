<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Exception\PromptGetException;
use Throwable;

/**
 * Helper for safe prompt execution with detailed error messages.
 *
 * Wraps execution and converts exceptions to PromptGetException
 * so the MCP SDK shows the actual error message to users.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class SafePromptExecution {
    use ExceptionFormatterTrait;

    /**
     * Execute a callable and convert any exceptions to PromptGetException.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws PromptGetException
     */
    public static function run(callable $callback): mixed {
        try {
            return $callback();
        } catch (PromptGetException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PromptGetException(
                self::formatErrorMessage($e),
                (int) $e->getCode(),
                $e,
            );
        }
    }
}
