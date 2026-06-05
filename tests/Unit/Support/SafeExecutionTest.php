<?php

declare(strict_types=1);

use craft\errors\StaleResourceException;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\SafeExecution;

describe('SafeExecution::run()', function () {
    it('returns the callback result on success', function () {
        expect(SafeExecution::run(fn (): int => 42))->toBe(42);
    });

    it('rethrows a ToolCallException unchanged', function () {
        $original = new ToolCallException('boom');

        try {
            SafeExecution::run(function () use ($original): never {
                throw $original;
            });
            $this->fail('Expected the ToolCallException to be rethrown.');
        } catch (ToolCallException $e) {
            expect($e)->toBe($original);
        }
    });

    it('wraps a generic throwable as a ToolCallException', function () {
        $cause = new RuntimeException('kaboom');

        try {
            SafeExecution::run(function () use ($cause): never {
                throw $cause;
            });
            $this->fail('Expected a ToolCallException to be thrown.');
        } catch (ToolCallException $e) {
            expect($e->getPrevious())->toBe($cause);
        }
    });

    it('recovers from a stale project config and retries once', function () {
        $attempts = 0;

        $result = SafeExecution::run(function () use (&$attempts): string {
            $attempts++;
            if ($attempts === 1) {
                throw new StaleResourceException('The loaded project config is out-of-date.');
            }

            return 'recovered';
        });

        expect($result)->toBe('recovered')
            ->and($attempts)->toBe(2);
    });

    it('surfaces a persistent stale project config as a ToolCallException after one retry', function () {
        $attempts = 0;
        $stale = new StaleResourceException('The loaded project config is out-of-date.');

        try {
            SafeExecution::run(function () use (&$attempts, $stale): never {
                $attempts++;

                throw $stale;
            });
            $this->fail('Expected a ToolCallException to be thrown.');
        } catch (ToolCallException $e) {
            expect($e->getPrevious())->toBe($stale)
                ->and($attempts)->toBe(2);
        }
    });
});
