<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use yii\mutex\Mutex;

/**
 * Concrete Mutex for testing. Tracks released locks in-memory.
 */
class TestMutex extends Mutex {
    /** @var string[] */
    public array $released = [];

    public function init(): void {
        // Skip parent's shutdown function registration
    }

    protected function acquireLock($name, $timeout = 0): bool {
        return true;
    }

    protected function releaseLock($name): bool {
        $this->released[] = $name;

        return true;
    }
}
