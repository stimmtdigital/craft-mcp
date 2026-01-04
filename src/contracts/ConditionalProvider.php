<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\contracts;

/**
 * Interface for provider classes that are conditionally available.
 *
 * Implement this interface to make tools, prompts, or resources conditionally available.
 * The isAvailable() method is called during registration to determine
 * whether the provider's capabilities should be registered.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
interface ConditionalProvider {
    /**
     * Check if this provider is available.
     *
     * Return false to skip registration of all capabilities in this class.
     * Common use cases:
     * - Check if a required plugin is installed
     * - Check if a required service is configured
     * - Check environment-specific conditions
     */
    public static function isAvailable(): bool;
}
