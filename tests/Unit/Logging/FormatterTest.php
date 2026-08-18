<?php

declare(strict_types=1);

use Mcp\Schema\Content\TextContent;
use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\Formatter;
use stimmt\craft\Mcp\logging\StackFrame;
use stimmt\craft\Mcp\text\Palette;

/**
 * Colour on by default here: that is the output read_logs produced before the
 * palette existed, and these assertions are the regression baseline for it.
 *
 * @param Entry[] $entries
 */
function formatLogs(array $entries, bool $color = true): TextContent {
    return (new Formatter(new Palette($color)))->format($entries);
}

describe('Formatter', function () {
    describe('format()', function () {
        it('returns TextContent', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'info',
                    category: 'application',
                    message: 'Test message',
                    file: 'web.log',
                ),
            ];

            $result = formatLogs($entries);

            expect($result)->toBeInstanceOf(TextContent::class);
        });

        it('handles empty entries', function () {
            $result = formatLogs([]);

            expect($result)->toBeInstanceOf(TextContent::class)
                ->and($result->text)->toBe('No log entries found.');
        });

        it('formats single entry', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'info',
                    category: 'app\\Service',
                    message: 'Test message',
                    file: 'web.log',
                ),
            ];

            $result = formatLogs($entries);

            expect($result->text)
                ->toContain('2026-01-07 10:30:00')
                ->toContain('INFO')
                ->toContain('app\\Service')
                ->toContain('Test message');
        });

        it('formats multiple entries with separator', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'info',
                    category: 'app',
                    message: 'First',
                    file: 'web.log',
                ),
                new Entry(
                    timestamp: '2026-01-07 10:30:01',
                    channel: 'web',
                    level: 'error',
                    category: 'app',
                    message: 'Second',
                    file: 'web.log',
                ),
            ];

            $result = formatLogs($entries);

            expect($result->text)
                ->toContain('First')
                ->toContain('Second')
                ->toContain("\n\n"); // Double newline separator
        });

        it('colorizes error level in red', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'error',
                    category: 'app',
                    message: 'Error occurred',
                    file: 'web.log',
                ),
            ];

            $result = formatLogs($entries);

            // Red ANSI code
            expect($result->text)->toContain("\033[31mERROR\033[0m");
        });

        it('colorizes warning level in yellow', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'warning',
                    category: 'app',
                    message: 'Warning issued',
                    file: 'web.log',
                ),
            ];

            $result = formatLogs($entries);

            // Yellow ANSI code
            expect($result->text)->toContain("\033[33mWARNING\033[0m");
        });

        it('uses dim for info level', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'info',
                    category: 'app',
                    message: 'Info message',
                    file: 'web.log',
                ),
            ];

            $result = formatLogs($entries);

            // Dim ANSI code
            expect($result->text)->toContain("\033[2mINFO\033[0m");
        });

        it('includes stack trace when present', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'error',
                    category: 'app',
                    message: 'Exception thrown',
                    file: 'web.log',
                    stackTrace: [
                        new StackFrame(0, '/var/www/file.php', 123, 'SomeClass->method()'),
                        new StackFrame(1, '/var/www/other.php', 456, 'OtherClass->call()'),
                    ],
                ),
            ];

            $result = formatLogs($entries);

            expect($result->text)
                ->toContain('#0 /var/www/file.php(123): SomeClass->method()')
                ->toContain('#1 /var/www/other.php(456): OtherClass->call()');
        });

        it('indents stack trace lines', function () {
            $entries = [
                new Entry(
                    timestamp: '2026-01-07 10:30:00',
                    channel: 'web',
                    level: 'error',
                    category: 'app',
                    message: 'Error',
                    file: 'web.log',
                    stackTrace: [
                        new StackFrame(0, '/file.php', 1, 'test()'),
                    ],
                ),
            ];

            $result = formatLogs($entries);

            // Stack trace should be on new line with indentation (wrapped in dim ANSI)
            expect($result->text)->toContain("\n\033[2m  #0");
        });
    });
});

describe('Formatter without colour', function () {
    it('emits the same text with no escape sequences', function () {
        $entries = [
            new Entry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'error',
                category: 'app\\Service',
                message: 'Error occurred',
                file: 'web.log',
                stackTrace: [new StackFrame(0, '/file.php', 1, 'test()')],
            ),
        ];

        $plain = formatLogs($entries, color: false)->text;

        expect($plain)->not->toContain("\033")
            ->and($plain)->toContain('2026-01-07 10:30:00')
            ->and($plain)->toContain('[ERROR]')
            ->and($plain)->toContain('app\\Service: Error occurred')
            ->and($plain)->toContain('  #0 /file.php(1): test()');
    });

    it('lays out identically to the coloured output', function () {
        $entries = [
            new Entry(
                timestamp: '2026-01-07 10:30:00',
                channel: 'web',
                level: 'warning',
                category: 'app',
                message: 'Careful',
                file: 'web.log',
            ),
        ];

        $stripped = preg_replace('/\033\[[0-9;]*m/', '', formatLogs($entries)->text);

        expect($stripped)->toBe(formatLogs($entries, color: false)->text);
    });
});
