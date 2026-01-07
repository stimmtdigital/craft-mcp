<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\installer;

/**
 * Result of a configuration write operation.
 */
final readonly class WriteResult {
    public function __construct(
        public string $path,
        public bool $created,
        public bool $updated,
        public bool $serverExisted,
    ) {
    }

    /**
     * Create a result for a newly created file.
     */
    public static function created(string $path): self {
        return new self(
            path: $path,
            created: true,
            updated: false,
            serverExisted: false,
        );
    }

    /**
     * Create a result for an updated existing file (new server added).
     */
    public static function added(string $path): self {
        return new self(
            path: $path,
            created: false,
            updated: true,
            serverExisted: false,
        );
    }

    /**
     * Create a result for an updated existing file (server overwritten).
     */
    public static function overwritten(string $path): self {
        return new self(
            path: $path,
            created: false,
            updated: true,
            serverExisted: true,
        );
    }

    /**
     * Get a human-readable description of what happened.
     */
    public function getDescription(): string {
        if ($this->created) {
            return 'Created';
        }

        if ($this->serverExisted) {
            return 'Updated (overwritten)';
        }

        return 'Updated';
    }
}
