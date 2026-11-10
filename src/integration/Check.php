<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

/**
 * One line of the plugin's status report: what was checked, how it stands,
 * and, when something needs doing, what to do.
 */
final readonly class Check {
    public function __construct(
        public string $label,
        public Level $level,
        public string $message,
        public ?string $fix = null,
    ) {
    }
}
