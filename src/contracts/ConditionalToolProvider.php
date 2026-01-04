<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\contracts;

/**
 * Interface for tool classes that are conditionally available.
 *
 * Implement this interface to make an entire tool class conditionally available.
 * The isAvailable() method is called during tool registration to determine
 * whether the class's tools should be registered.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
interface ConditionalToolProvider {
    /**
     * Check if this tool provider is available.
     *
     * Return false to skip registration of all tools in this class.
     * Common use cases:
     * - Check if a required plugin is installed
     * - Check if a required service is configured
     * - Check environment-specific conditions
     */
    public static function isAvailable(): bool;
}
