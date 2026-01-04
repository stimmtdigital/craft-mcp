<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\completions;

use Mcp\Capability\Completion\ProviderInterface;

/**
 * Base class for Craft-specific completion providers.
 *
 * Provides caching and common prefix-based filtering logic.
 * Subclasses only need to implement fetchValues() to return available completions.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
abstract class CraftCompletionProvider implements ProviderInterface {
    /** @var string[]|null Cached values */
    private ?array $cachedValues = null;

    /**
     * Get completions for the current value.
     *
     * Returns all values if currentValue is empty, otherwise filters by prefix.
     *
     * @return string[]
     */
    public function getCompletions(string $currentValue): array {
        $values = $this->getCachedValues();

        if ($currentValue === '') {
            return $values;
        }

        $lowerCurrent = strtolower($currentValue);

        return array_values(array_filter(
            $values,
            fn (string $value): bool => str_starts_with(strtolower($value), $lowerCurrent),
        ));
    }

    /**
     * Fetch the available completion values.
     *
     * This method is called once and cached for subsequent requests.
     *
     * @return string[]
     */
    abstract protected function fetchValues(): array;

    /**
     * Get cached values, fetching if necessary.
     *
     * @return string[]
     */
    private function getCachedValues(): array {
        return $this->cachedValues ??= $this->fetchValues();
    }

    /**
     * Clear the cached values.
     *
     * Useful for testing or when underlying data has changed.
     */
    public function clearCache(): void {
        $this->cachedValues = null;
    }
}
