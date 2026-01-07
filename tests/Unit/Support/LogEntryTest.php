<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\LogEntry;
use stimmt\craft\Mcp\support\StackFrame;

describe('LogEntry', function () {
    it('creates from constructor', function () {
        $entry = new LogEntry(
            timestamp: '2026-01-07 10:30:00',
            channel: 'web',
            level: 'error',
            category: 'application',
            message: 'Something went wrong',
            file: 'web.log',
        );

        expect($entry->timestamp)->toBe('2026-01-07 10:30:00')
            ->and($entry->channel)->toBe('web')
            ->and($entry->level)->toBe('error')
            ->and($entry->category)->toBe('application')
            ->and($entry->message)->toBe('Something went wrong')
            ->and($entry->file)->toBe('web.log')
            ->and($entry->stackTrace)->toBeNull();
    });

    it('supports stack trace', function () {
        $frames = [
            new StackFrame(0, '/file1.php', 10, 'Class->method()'),
            new StackFrame(1, '/file2.php', 20, 'OtherClass->call()'),
        ];

        $entry = new LogEntry(
            timestamp: '2026-01-07 10:30:00',
            channel: 'web',
            level: 'error',
            category: 'application',
            message: 'Error with trace',
            file: 'web.log',
            stackTrace: $frames,
        );

        expect($entry->stackTrace)->toHaveCount(2)
            ->and($entry->hasStackTrace())->toBeTrue();
    });

    describe('matchesLevel()', function () {
        it('matches exact level', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'application',
                message: 'Test',
                file: 'web.log',
            );

            expect($entry->matchesLevel('error'))->toBeTrue()
                ->and($entry->matchesLevel('warning'))->toBeFalse();
        });

        it('is case insensitive', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'application',
                message: 'Test',
                file: 'web.log',
            );

            expect($entry->matchesLevel('ERROR'))->toBeTrue()
                ->and($entry->matchesLevel('Error'))->toBeTrue();
        });
    });

    describe('matchesPattern()', function () {
        it('finds substring in message', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'application',
                message: 'Database connection failed',
                file: 'web.log',
            );

            expect($entry->matchesPattern('database'))->toBeTrue()
                ->and($entry->matchesPattern('connection'))->toBeTrue()
                ->and($entry->matchesPattern('network'))->toBeFalse();
        });

        it('is case insensitive', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'application',
                message: 'Database Error',
                file: 'web.log',
            );

            expect($entry->matchesPattern('DATABASE'))->toBeTrue()
                ->and($entry->matchesPattern('error'))->toBeTrue();
        });
    });

    describe('hasStackTrace()', function () {
        it('returns false when null', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'info',
                category: 'application',
                message: 'Test',
                file: 'web.log',
            );

            expect($entry->hasStackTrace())->toBeFalse();
        });

        it('returns false when empty array', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'info',
                category: 'application',
                message: 'Test',
                file: 'web.log',
                stackTrace: [],
            );

            expect($entry->hasStackTrace())->toBeFalse();
        });

        it('returns true when has frames', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'application',
                message: 'Test',
                file: 'web.log',
                stackTrace: [new StackFrame(0, '/file.php', 1, 'test()')],
            );

            expect($entry->hasStackTrace())->toBeTrue();
        });
    });

    describe('toArray()', function () {
        it('converts entry without stack trace', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'info',
                category: 'application',
                message: 'Test message',
                file: 'web.log',
            );

            $array = $entry->toArray();

            expect($array)->toBe([
                'timestamp' => '2026-01-07 10:30:00',
                'channel' => 'web',
                'level' => 'info',
                'category' => 'application',
                'message' => 'Test message',
                'file' => 'web.log',
            ]);
        });

        it('includes stack trace when present', function () {
            $entry = new LogEntry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'application',
                message: 'Error',
                file: 'web.log',
                stackTrace: [
                    new StackFrame(0, '/file.php', 10, 'test()'),
                ],
            );

            $array = $entry->toArray();

            expect($array)->toHaveKey('stackTrace')
                ->and($array['stackTrace'])->toHaveCount(1)
                ->and($array['stackTrace'][0])->toBe([
                    'index' => 0,
                    'file' => '/file.php',
                    'line' => 10,
                    'call' => 'test()',
                ]);
        });
    });
});
