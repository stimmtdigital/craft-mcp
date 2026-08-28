<?php

declare(strict_types=1);

use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\Search;

$entry = static fn (string $level, string $message): Entry => new Entry(
    timestamp: '2026-01-07 10:30:00',
    channel: 'web',
    level: $level,
    category: 'application',
    message: $message,
    file: 'web.log',
);

describe('Search::matches()', function () use ($entry) {
    it('keeps everything when nothing is asked of it', function () use ($entry) {
        expect((new Search())->matches($entry('info', 'Anything')))->toBeTrue();
    });

    it('matches a level exactly rather than as a minimum', function () use ($entry) {
        $search = new Search(level: 'warning');

        expect($search->matches($entry('warning', 'Careful')))->toBeTrue()
            ->and($search->matches($entry('error', 'Broken')))->toBeFalse();
    });

    it('matches a pattern case-insensitively anywhere in the message', function () use ($entry) {
        $search = new Search(pattern: 'DEPRECAT');

        expect($search->matches($entry('info', "Request context:\nbody: deprecations")))->toBeTrue();
    });

    it('ignores continuation lines when scoped to the headline', function () use ($entry) {
        $search = new Search(pattern: 'deprecat', headlineOnly: true);

        expect($search->matches($entry('info', "Request context:\nbody: deprecations")))->toBeFalse()
            ->and($search->matches($entry('warning', "Deprecated: strlen()\n#0 /app.php(1): x()")))->toBeTrue();
    });

    it('requires the level and the pattern together', function () use ($entry) {
        $search = new Search(level: 'error', pattern: 'timeout');

        expect($search->matches($entry('error', 'Connection timeout')))->toBeTrue()
            ->and($search->matches($entry('info', 'Connection timeout')))->toBeFalse()
            ->and($search->matches($entry('error', 'Connection refused')))->toBeFalse();
    });
});
