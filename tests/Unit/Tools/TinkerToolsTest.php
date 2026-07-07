<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use stimmt\craft\Mcp\Tests\Fixtures\TestMutex;
use stimmt\craft\Mcp\tools\TinkerTools;

describe('TinkerTools blocked patterns', function () {
    it('blocks shell exec calls', function () {
        $result = (new TinkerTools())->tinker("exec('ls');");

        expect($result->text)->toContain('SecurityError');
    });

    it('blocks unbounded output buffer teardown loops', function (string $code) {
        $result = (new TinkerTools())->tinker($code);

        expect($result->text)->toContain('SecurityError');
    })->with([
        'spaced' => 'while (ob_get_level() > 0) { ob_end_clean(); }',
        'compact' => 'while(ob_get_level()){ob_end_clean();}',
        'negated comparison' => 'while (ob_get_level() !== 0) { ob_end_clean(); }',
    ]);
});

describe('TinkerTools output buffer handling', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        $mutex = new TestMutex();
        $projectConfig = new class () {
            private bool $_locked = false;

            public function setLocked(bool $value): void {
                $this->_locked = $value;
            }

            public function isLocked(): bool {
                return $this->_locked;
            }
        };

        Craft::$app = new class ($mutex, $projectConfig) {
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

    it('captures echoed output', function () {
        $result = (new TinkerTools())->tinker("echo 'hello-stdout'; return 1;");

        expect($result->text)->toContain('hello-stdout');
    });

    it('preserves outer buffers when user code closes the capture buffer', function () {
        ob_start();
        $level = ob_get_level();

        $result = (new TinkerTools())->tinker("ob_end_clean(); return 'done';");

        $intact = ob_get_level() === $level;
        if ($intact) {
            ob_end_clean();
        }

        expect($intact)->toBeTrue()
            ->and($result->text)->toContain('done');
    });

    it('keeps the original error when user code closes the capture buffer and throws', function () {
        ob_start();
        $level = ob_get_level();

        $result = (new TinkerTools())->tinker("ob_end_clean(); throw new RuntimeException('original-error');");

        $intact = ob_get_level() === $level;
        if ($intact) {
            ob_end_clean();
        }

        expect($intact)->toBeTrue()
            ->and($result->text)->toContain('original-error');
    });

    it('closes extra buffers opened by user code', function () {
        $level = ob_get_level();

        $result = (new TinkerTools())->tinker("ob_start(); echo 'inner'; return 'done';");

        expect(ob_get_level())->toBe($level)
            ->and($result->text)->toContain('done')
            ->and($result->text)->toContain('inner');
    });
});
