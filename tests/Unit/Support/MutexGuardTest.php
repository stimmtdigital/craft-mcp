<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use stimmt\craft\Mcp\support\MutexGuard;
use stimmt\craft\Mcp\Tests\Fixtures\TestMutex;

beforeEach(function () {
    $this->originalApp = Craft::$app;
    $this->mutex = new TestMutex();
    $this->projectConfig = new class () {
        private bool $_locked = false;

        public function setLocked(bool $value): void {
            $this->_locked = $value;
        }

        public function isLocked(): bool {
            return $this->_locked;
        }
    };

    Craft::$app = new class ($this->mutex, $this->projectConfig) {
        public function __construct(
            private readonly object $mutex,
            private readonly object $projectConfig,
        ) {
        }

        public function getMutex(): object {
            return $this->mutex;
        }

        public function getProjectConfig(): object {
            return $this->projectConfig;
        }
    };
});

afterEach(function () {
    Craft::$app = $this->originalApp;
});

describe('MutexGuard::releaseAll()', function () {
    it('releases all held mutex locks', function () {
        $this->mutex->acquire('project-config');
        $this->mutex->acquire('structure:42');

        MutexGuard::releaseAll();

        expect($this->mutex->released)->toBe(['project-config', 'structure:42']);
    });

    it('resets ProjectConfig _locked flag', function () {
        $this->projectConfig->setLocked(true);
        expect($this->projectConfig->isLocked())->toBeTrue();

        MutexGuard::releaseAll();

        expect($this->projectConfig->isLocked())->toBeFalse();
    });

    it('is a safe no-op when no locks are held', function () {
        MutexGuard::releaseAll();

        expect($this->mutex->released)->toBe([]);
    });

    it('is a safe no-op when _locked is already false', function () {
        $this->projectConfig->setLocked(false);

        MutexGuard::releaseAll();

        expect($this->projectConfig->isLocked())->toBeFalse();
    });

    it('releases locks acquired after previous releaseAll', function () {
        $this->mutex->acquire('project-config');
        MutexGuard::releaseAll();

        $this->mutex->released = [];
        $this->mutex->acquire('project-config');
        MutexGuard::releaseAll();

        expect($this->mutex->released)->toBe(['project-config']);
    });
});
