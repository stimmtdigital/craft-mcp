<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps what it was told, so the paths where a transport gives up
 * on a client can be asserted on rather than inferred from behaviour that looks
 * the same either way.
 */
class RecordingLogger extends AbstractLogger {
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    }

    /**
     * @return list<string>
     */
    public function levels(): array {
        return array_column($this->records, 'level');
    }
}
