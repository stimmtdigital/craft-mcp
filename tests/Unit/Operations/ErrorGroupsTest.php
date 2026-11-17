<?php

declare(strict_types=1);

use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\StackFrame;
use stimmt\craft\Mcp\operations\ErrorGroups;

$error = static fn (string $timestamp, string $message, string $file = 'web-2026-11-17.log', ?array $trace = null): Entry => new Entry(
    timestamp: $timestamp,
    channel: 'web',
    level: 'error',
    category: 'application',
    message: $message,
    file: $file,
    stackTrace: $trace,
);

$groups = static fn (array $entries, string $since = '2026-11-16 12:00:00'): ErrorGroups => new ErrorGroups(
    $entries,
    $since,
    new DateTimeZone('Europe/Amsterdam'),
);

describe('ErrorGroups', function () use ($error, $groups) {
    it('groups recurrences of one error, counting them and spanning first to last seen', function () use ($error, $groups) {
        $result = $groups([
            $error('2026-11-17 10:00:00', 'RuntimeException: Order 9 failed in /app/modules/Shop.php:5'),
            $error('2026-11-17 09:00:00', 'RuntimeException: Order 7 failed in /app/modules/Shop.php:5', 'queue-2026-11-17.log'),
            $error('2026-11-17 08:00:00', 'RuntimeException: Order 3 failed in /app/modules/Shop.php:5'),
        ])->groups();

        expect($result)->toHaveCount(1)
            ->and($result[0])->toMatchArray([
                'kind' => 'RuntimeException',
                // The newest occurrence is the example.
                'message' => 'Order 9 failed',
                'count' => 3,
                'firstSeen' => '2026-11-17T08:00:00+01:00',
                'lastSeen' => '2026-11-17T10:00:00+01:00',
                'sources' => ['web', 'queue'],
            ])
            ->and($result[0]['example']['site'])->toBe('/app/modules/Shop.php:5');
    });

    it('sorts by count, then by the most recent', function () use ($error, $groups) {
        $result = $groups([
            $error('2026-11-17 11:00:00', 'Lone newest'),
            $error('2026-11-17 10:00:00', 'Twice A'),
            $error('2026-11-17 09:00:00', 'Lone older'),
            $error('2026-11-17 08:00:00', 'Twice A'),
        ])->groups();

        expect(array_column($result, 'message'))->toBe(['Twice A', 'Lone newest', 'Lone older']);
    });

    // The parser hands entries over newest first, but the order is decided
    // here rather than inherited from it.
    it('breaks a tie in count by the most recent, whatever order the entries came in', function () use ($error, $groups) {
        $result = $groups([
            $error('2026-11-17 08:00:00', 'Older'),
            $error('2026-11-17 11:00:00', 'Newer'),
        ])->groups();

        expect(array_column($result, 'message'))->toBe(['Newer', 'Older']);
    });

    it('leaves out errors before the window and Craft\'s request context entries', function () use ($error, $groups) {
        $grouped = $groups([
            $error('2026-11-17 10:00:00', 'Request context: $_GET = []'),
            $error('2026-11-17 09:00:00', 'Inside'),
            $error('2026-11-16 11:59:59', 'Before the window'),
        ]);

        expect(array_column($grouped->groups(), 'message'))->toBe(['Inside'])
            ->and($grouped->inWindow())->toHaveCount(1);
    });

    it('shows at most eight frames and a message of at most 300 characters', function () use ($error, $groups) {
        $frames = array_map(static fn (int $i): StackFrame => new StackFrame($i, "/app/f{$i}.php", $i, 'x()'), range(0, 11));
        $result = $groups([$error('2026-11-17 10:00:00', str_repeat('a', 400), trace: $frames)])->groups();

        expect($result[0]['example']['trace'])->toHaveCount(ErrorGroups::TRACE_FRAMES)
            ->and(mb_strlen($result[0]['message']))->toBe(ErrorGroups::MESSAGE_LENGTH);
    });
});
