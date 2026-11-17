<?php

declare(strict_types=1);

use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\StackFrame;
use stimmt\craft\Mcp\operations\ErrorSignature;

/**
 * Craft logs an exception as `(string) $exception`. In dev mode the trace is on
 * lines of its own and the parser splits it into frames; outside dev mode
 * Craft folds line breaks into spaces, so the same exception is one line.
 */
$entry = static fn (string $message, string $category = 'application', ?array $trace = null, string $file = 'web-2026-11-17.log'): Entry => new Entry(
    timestamp: '2026-11-17 10:00:00',
    channel: 'web',
    level: 'error',
    category: $category,
    message: $message,
    file: $file,
    stackTrace: $trace,
);

describe('ErrorSignature::of()', function () use ($entry) {
    it('reads a production line, with its trace and context inline', function () use ($entry) {
        $signature = ErrorSignature::of($entry(
            'yii\base\ErrorException: Undefined variable $order in /var/www/vendor/twig/twig/src/Template.php:12 '
            . 'Stack trace: #0 /var/www/vendor/twig/twig/src/Template.php(40): Twig\Template->x() '
            . '#1 [internal function]: y() #2 /var/www/modules/shop/Cart.php(88): Twig\Template->render() '
            . '#3 {main} {"memory":4194304,"exception":"[object] (...)"}',
            'yii\base\ErrorException:2',
        ));

        expect($signature->kind)->toBe('yii\base\ErrorException')
            ->and($signature->text)->toBe('Undefined variable $order')
            ->and(array_map(static fn (StackFrame $f): int => $f->index, $signature->frames))->toBe([0, 2])
            ->and($signature->frames[1]->call)->toBe('Twig\Template->render()')
            // The throw site and frame #0 are in vendor/, so the project's own
            // first frame is where a fix goes.
            ->and($signature->site)->toBe('/var/www/modules/shop/Cart.php:88');
    });

    it('reads a dev-mode entry whose frames the parser already split', function () use ($entry) {
        $signature = ErrorSignature::of($entry(
            'Twig\Error\RuntimeError: Variable "entry" does not exist. in /app/templates/_blog/entry.twig:4',
            trace: [new StackFrame(0, '/app/vendor/twig/twig/src/Template.php', 10, 'x()')],
        ));

        expect($signature->kind)->toBe('Twig\Error\RuntimeError')
            ->and($signature->site)->toBe('/app/templates/_blog/entry.twig:4')
            ->and($signature->frames)->toHaveCount(1);
    });

    it('takes the class from the category when the message does not name one', function () use ($entry) {
        expect(ErrorSignature::of($entry('Page not found.', 'yii\web\HttpException:404'))->kind)
            ->toBe('yii\web\HttpException');
    });

    it('calls an entry without an exception a message, and drops the context JSON', function () use ($entry) {
        $signature = ErrorSignature::of($entry('Could not sync order 1234 {"memory":123}'));

        expect($signature->kind)->toBe(ErrorSignature::PLAIN)
            ->and($signature->text)->toBe('Could not sync order 1234')
            ->and($signature->site)->toBeNull();
    });

    it('keys two occurrences that differ only in their values together', function () use ($entry) {
        $a = ErrorSignature::of($entry("RuntimeException: Element 1234 with uid 'a1b2' not found in /app/modules/A.php:9"));
        $b = ErrorSignature::of($entry("RuntimeException: Element 98 with uid 'ffee' not found in /app/modules/A.php:9"));
        $elsewhere = ErrorSignature::of($entry("RuntimeException: Element 98 with uid 'ffee' not found in /app/modules/B.php:9"));

        expect($a->key())->toBe($b->key())->and($a->key())->not->toBe($elsewhere->key());
    });
});

describe('ErrorSignature::normalize()', function () {
    it('masks what varies between occurrences', function (string $message, string $normalized) {
        expect(ErrorSignature::normalize($message))->toBe($normalized);
    })->with([
        'numbers' => ['Entry 1234 has 2 drafts', 'Entry {n} has {n} drafts'],
        'quoted values' => ['Field "body" on \'blog\'', 'Field "?" on \'?\''],
        'uuids' => ['Missing 0b6c3a52-7e1f-4b4e-9c8a-3d2f1e0a9b8c', 'Missing {uuid}'],
        'hex ids' => ['Object 0x7f3a at deadbeef1234', 'Object {hex} at {hex}'],
        'path directories' => ['Cannot write /srv/releases/42/storage/runtime/x.php', 'Cannot write …/x.php'],
        'whitespace' => ["Two\n  lines", 'Two lines'],
    ]);
});

describe('ErrorSignature::source()', function () {
    it('names the log the way stimmt_logs_read takes it', function (string $file, string $source) {
        expect(ErrorSignature::source($file))->toBe($source);
    })->with([
        ['web-2026-11-17.log', 'web'],
        ['console.log', 'console'],
        ['plugins/formie-2026-11-17.log', 'formie'],
    ]);
});
