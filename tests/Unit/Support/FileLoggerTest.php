<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use stimmt\craft\Mcp\support\FileLogger;

beforeEach(function () {
    $this->logPath = sys_get_temp_dir() . '/mcp-test-' . uniqid() . '.log';
});

afterEach(function () {
    if (file_exists($this->logPath)) {
        unlink($this->logPath);
    }
});

describe('FileLogger', function () {
    describe('log level filtering', function () {
        it('writes messages at or above the minimum level', function () {
            $logger = new FileLogger($this->logPath, 'warning');

            $logger->error('an error');
            $logger->warning('a warning');

            $content = file_get_contents($this->logPath);
            expect($content)
                ->toContain('an error')
                ->toContain('a warning');
        });

        it('suppresses messages below the minimum level', function () {
            $logger = new FileLogger($this->logPath, 'error');

            $logger->debug('debug noise');
            $logger->info('info noise');
            $logger->warning('warning noise');

            expect(file_exists($this->logPath))->toBeFalse();
        });

        it('defaults to error level when no minimum specified', function () {
            $logger = new FileLogger($this->logPath);

            $logger->info('should be suppressed');

            expect(file_exists($this->logPath))->toBeFalse();
        });

        it('writes error messages when using the default minimum level', function () {
            $logger = new FileLogger($this->logPath);

            $logger->error('an error gets through');

            $content = file_get_contents($this->logPath);
            expect($content)->toContain('an error gets through');
        });

        it('writes debug messages when minimum is debug', function () {
            $logger = new FileLogger($this->logPath, 'debug');

            $logger->debug('debug detail');

            $content = file_get_contents($this->logPath);
            expect($content)->toContain('debug detail');
        });

        it('treats unknown incoming log levels as warning priority (suppressed below error)', function () {
            $logger = new FileLogger($this->logPath, 'error');

            $logger->log('custom', 'custom message');

            // 'custom' falls back to priority 3 (warning), which is below error (4)
            expect(file_exists($this->logPath))->toBeFalse();
        });

        it('treats unknown incoming log levels as warning priority (written at or above warning)', function () {
            $logger = new FileLogger($this->logPath, 'warning');

            $logger->log('custom', 'custom message');

            // 'custom' falls back to priority 3 (warning), which meets the warning (3) threshold
            $content = file_get_contents($this->logPath);
            expect($content)->toContain('custom message');
        });

        it('throws on invalid minimum level in constructor', function () {
            expect(fn () => new FileLogger($this->logPath, 'invalid'))
                ->toThrow(\InvalidArgumentException::class);
        });
    });
});
