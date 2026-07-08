<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

/**
 * Per-operation carrier: target site handle plus collected warnings.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Context {
    /** @var Warning[] */
    private array $warnings = [];

    public function __construct(
        public readonly ?string $site = null,
    ) {
    }

    public function warn(Warning $warning): void {
        $this->warnings[] = $warning;
    }

    /**
     * @return Warning[]
     */
    public function warnings(): array {
        return $this->warnings;
    }
}
