<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

/**
 * Immutable value object representing a single stack trace frame.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class StackFrame {
    public function __construct(
        public int $index,
        public string $file,
        public int $line,
        public string $call,
    ) {
    }

    /**
     * Create a StackFrame from a regex match.
     *
     * @param array<int, string> $matches Regex matches [full, index, file, line, call]
     */
    public static function fromMatch(array $matches): self {
        return new self(
            index: (int) $matches[1],
            file: $matches[2],
            line: (int) $matches[3],
            call: trim($matches[4]),
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array{index: int, file: string, line: int, call: string}
     */
    public function toArray(): array {
        return [
            'index' => $this->index,
            'file' => $this->file,
            'line' => $this->line,
            'call' => $this->call,
        ];
    }
}
