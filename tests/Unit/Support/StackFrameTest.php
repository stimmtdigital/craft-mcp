<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\StackFrame;

describe('StackFrame', function () {
    it('creates from constructor', function () {
        $frame = new StackFrame(
            index: 0,
            file: '/var/www/html/vendor/file.php',
            line: 123,
            call: 'SomeClass->method()',
        );

        expect($frame->index)->toBe(0)
            ->and($frame->file)->toBe('/var/www/html/vendor/file.php')
            ->and($frame->line)->toBe(123)
            ->and($frame->call)->toBe('SomeClass->method()');
    });

    it('creates from regex match', function () {
        $matches = [
            '#0 /var/www/html/vendor/file.php(123): SomeClass->method()',
            '0',
            '/var/www/html/vendor/file.php',
            '123',
            'SomeClass->method()',
        ];

        $frame = StackFrame::fromMatch($matches);

        expect($frame->index)->toBe(0)
            ->and($frame->file)->toBe('/var/www/html/vendor/file.php')
            ->and($frame->line)->toBe(123)
            ->and($frame->call)->toBe('SomeClass->method()');
    });

    it('converts to array', function () {
        $frame = new StackFrame(
            index: 5,
            file: '/path/to/file.php',
            line: 42,
            call: 'MyClass::staticMethod()',
        );

        $array = $frame->toArray();

        expect($array)->toBe([
            'index' => 5,
            'file' => '/path/to/file.php',
            'line' => 42,
            'call' => 'MyClass::staticMethod()',
        ]);
    });

    it('trims whitespace from call', function () {
        $matches = [
            '#0 /file.php(1):   SomeClass->method()  ',
            '0',
            '/file.php',
            '1',
            '  SomeClass->method()  ',
        ];

        $frame = StackFrame::fromMatch($matches);

        expect($frame->call)->toBe('SomeClass->method()');
    });
});
