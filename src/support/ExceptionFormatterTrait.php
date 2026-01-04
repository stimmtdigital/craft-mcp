<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Throwable;

/**
 * Shared exception formatting for Safe*Execution classes.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
trait ExceptionFormatterTrait {
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
        $filename = basename($e->getFile());

        return "{$filename}:{$e->getLine()}";
    }
}
