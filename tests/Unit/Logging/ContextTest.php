<?php

declare(strict_types=1);

use stimmt\craft\Mcp\logging\Context;

function decoded(array $context): array {
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((new Context())->encode($context), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

function helperException(): RuntimeException {
    return new RuntimeException('from helper');
}

function deepException(int $depth): RuntimeException {
    if ($depth <= 0) {
        return new RuntimeException('deep');
    }

    return deepException($depth - 1);
}

describe('Context', function () {
    describe('plain context', function () {
        it('leaves scalar values untouched', function () {
            expect(decoded(['name' => 'reload_mcp', 'count' => 3, 'ok' => false]))
                ->toBe(['name' => 'reload_mcp', 'count' => 3, 'ok' => false]);
        });

        it('leaves nested arrays untouched', function () {
            expect(decoded(['tools' => ['a' => ['b' => ['c' => 1]]]]))
                ->toBe(['tools' => ['a' => ['b' => ['c' => 1]]]]);
        });

        it('does not escape slashes', function () {
            expect((new Context())->encode(['path' => 'storage/logs/mcp-server.log']))
                ->toContain('storage/logs/mcp-server.log');
        });
    });

    describe('throwables', function () {
        it('expands a throwable into a readable structure', function () {
            $exception = new RuntimeException('boom', 42);

            $context = decoded(['exception' => $exception]);

            expect($context['exception'])
                ->toHaveKeys(['class', 'message', 'code', 'file', 'line', 'trace'])
                ->and($context['exception']['class'])->toBe(RuntimeException::class)
                ->and($context['exception']['message'])->toBe('boom')
                ->and($context['exception']['code'])->toBe(42)
                ->and($context['exception']['file'])->toBe(__FILE__)
                ->and($context['exception']['line'])->toBe($exception->getLine());
        });

        it('expands throwables nested inside arrays', function () {
            $context = decoded(['errors' => [['cause' => new LogicException('nested')]]]);

            expect($context['errors'][0]['cause']['message'])->toBe('nested');
        });

        it('walks the previous chain', function () {
            $root = new RuntimeException('root cause');
            $middle = new LogicException('middle', 0, $root);
            $top = new RuntimeException('top', 0, $middle);

            $context = decoded(['exception' => $top]);

            expect($context['exception']['previous']['message'])->toBe('middle')
                ->and($context['exception']['previous']['previous']['message'])->toBe('root cause')
                ->and($context['exception']['previous']['previous'])->not->toHaveKey('previous');
        });

        it('caps a long previous chain with a marker', function () {
            $exception = new RuntimeException('link 0');
            foreach (range(1, 12) as $link) {
                $exception = new RuntimeException("link {$link}", 0, $exception);
            }

            expect((new Context())->encode(['exception' => $exception]))
                ->toContain('[truncated: previous chain]');
        });

        it('includes stack frames as file, line and call', function () {
            $context = decoded(['exception' => helperException()]);

            expect($context['exception']['trace'][0])
                ->toContain('helperException()')
                ->toContain(__FILE__);
        });

        it('caps the stack trace and reports how many frames were dropped', function () {
            $context = decoded(['exception' => deepException(40)]);

            $trace = $context['exception']['trace'];

            expect(count($trace))->toBeLessThanOrEqual(16)
                ->and(end($trace))->toContain('more frames');
        });

        it('never emits a raw newline, so one entry stays one log line', function () {
            expect((new Context())->encode(['exception' => deepException(5)]))
                ->not->toContain("\n");
        });
    });

    describe('unencodable values', function () {
        it('degrades a resource instead of throwing', function () {
            $handle = fopen('php://memory', 'rb');

            $encoded = (new Context())->encode(['handle' => $handle]);
            fclose($handle);

            expect($encoded)->toBeString()->not->toBe('');
        });

        it('survives a self-referencing array', function () {
            $cycle = ['name' => 'loop'];
            $cycle['self'] = &$cycle;

            expect((new Context())->encode(['cycle' => $cycle]))
                ->toContain('[truncated: max depth]');
        });

        it('replaces invalid utf-8 rather than failing the whole line', function () {
            expect((new Context())->encode(['raw' => "valid\xB1\x31invalid"]))
                ->toContain('raw');
        });
    });
});
