<?php

declare(strict_types=1);

use stimmt\craft\Mcp\insight\DebugIndex;

/** An index entry as yii\debug\LogTarget::collectSummary() and export() write it. */
$entry = static fn (array $overrides = []): array => [
    'tag' => 'abc123',
    'url' => 'https://example.test/blog/post?token=reset-secret#top',
    'ajax' => 0,
    'method' => 'GET',
    'ip' => '203.0.113.9',
    'time' => 1794300000.25,
    'statusCode' => 200,
    'sqlCount' => 42,
    'excessiveCallersCount' => 0,
    'peakMemory' => 12582912,
    'processingTime' => 0.8125,
    ...$overrides,
];

describe('DebugIndex::summary()', function () use ($entry) {
    it('keeps timing, counts and the route, in milliseconds and megabytes', function () use ($entry) {
        expect(DebugIndex::summary('abc123', $entry()))->toBe([
            'tag' => 'abc123',
            'url' => 'https://example.test/blog/post',
            'method' => 'GET',
            'statusCode' => 200,
            'durationMs' => 812.5,
            'memoryMb' => 12.0,
            'queryCount' => 42,
            'time' => '2026-11-10T08:40:00+00:00',
        ]);
    });

    it('never carries the client IP or the query string', function () use ($entry) {
        $encoded = (string) json_encode(DebugIndex::summary('abc123', $entry()));

        expect($encoded)->not->toContain('203.0.113.9')
            ->and($encoded)->not->toContain('reset-secret');
    });

    it('reports what was not recorded as null rather than zero', function () {
        $summary = DebugIndex::summary('abc123', ['url' => '/x']);

        expect($summary['durationMs'])->toBeNull()
            ->and($summary['memoryMb'])->toBeNull()
            ->and($summary['queryCount'])->toBeNull()
            ->and($summary['statusCode'])->toBeNull()
            ->and($summary['time'])->toBeNull();
    });
});

describe('DebugIndex::safeUrl()', function () {
    it('drops the query string, fragment and credentials', function (string $url, string $safe) {
        expect(DebugIndex::safeUrl($url, 'GET'))->toBe($safe);
    })->with([
        ['https://example.test/a/b?x=1#f', 'https://example.test/a/b'],
        ['https://user:pass@example.test:8443/login?return=/admin', 'https://example.test:8443/login'],
        ['https://example.test', 'https://example.test/'],
        ['/relative/path?q=1', '/relative/path'],
    ]);

    // A console run's arguments can be a password typed on the command line.
    it('keeps only the route of a console command', function () {
        expect(DebugIndex::safeUrl('php yii users/set-password admin --password=hunter2', 'COMMAND'))
            ->toBe('php yii users/set-password');
    });
});

describe('DebugIndex::summaries()', function () use ($entry) {
    $manifest = static fn () => [
        'fast1' => $entry(['processingTime' => 0.05]),
        'slow1' => $entry(['processingTime' => 2.0]),
        'none1' => $entry(['processingTime' => null]),
        'mid1' => $entry(['processingTime' => 0.5]),
    ];

    it('sorts slowest first, with unrecorded durations last', function () use ($manifest) {
        expect(array_column(DebugIndex::summaries($manifest()), 'tag'))->toBe(['slow1', 'mid1', 'fast1', 'none1']);
    });

    it('keeps only requests at or above minMs, and never one whose duration is unknown', function () use ($manifest) {
        expect(array_column(DebugIndex::summaries($manifest(), 500.0), 'tag'))->toBe(['slow1', 'mid1'])
            ->and(array_column(DebugIndex::summaries($manifest(), 0.0), 'tag'))->toBe(['slow1', 'mid1', 'fast1']);
    });

    // A tag becomes a file name, so anything that is not a uniqid() is left out.
    it('skips index keys that are not tags', function () use ($entry) {
        $manifest = ['../../etc/passwd' => $entry(), 'ok1' => $entry(), 'x' => 'not an entry'];

        expect(array_column(DebugIndex::summaries($manifest), 'tag'))->toBe(['ok1']);
    });
});
