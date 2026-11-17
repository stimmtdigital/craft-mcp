<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\operations\LogRules;
use stimmt\craft\Mcp\operations\QueueRules;
use stimmt\craft\Mcp\operations\StorageRules;

describe('QueueRules', function () {
    it('makes failed jobs an error that points at the failures tool and the retry', function () {
        $check = QueueRules::failed(4);

        expect($check->level)->toBe(Level::Error)
            ->and($check->fix)->toContain('stimmt_queue_failures')
            ->and($check->fix)->toContain('queue_retry_all')
            ->and(QueueRules::failed(0)->level)->toBe(Level::Ok);
    });

    it('warns only once the oldest waiting job is over an hour overdue', function (int $waiting, ?int $age, Level $level) {
        expect(QueueRules::waiting($waiting, $age)->level)->toBe($level);
    })->with([
        'nothing waiting' => [0, null, Level::Ok],
        'just pushed' => [5, 10, Level::Ok],
        'exactly an hour' => [5, QueueRules::STALE_AFTER_SECONDS, Level::Ok],
        'an hour and a second' => [5, QueueRules::STALE_AFTER_SECONDS + 1, Level::Warning],
        'a day' => [1, 86400, Level::Warning],
    ]);

    it('says how long the oldest job has waited', function () {
        expect(QueueRules::waiting(2, 7200)->message)->toContain('2 hour(s)')
            ->and(QueueRules::waiting(2, 90000)->message)->toContain('1 day(s)');
    });
});

describe('StorageRules', function () {
    it('grades free space against the thresholds', function (float|false $bytes, Level $level) {
        expect(StorageRules::freeSpace($bytes)->level)->toBe($level);
    })->with([
        'plenty' => [50.0 * 1024 ** 3, Level::Ok],
        'exactly 1 GB' => [(float) StorageRules::WARN_BELOW_BYTES, Level::Ok],
        'just under 1 GB' => [StorageRules::WARN_BELOW_BYTES - 1.0, Level::Warning],
        'exactly 200 MB' => [(float) StorageRules::ERROR_BELOW_BYTES, Level::Warning],
        'just under 200 MB' => [StorageRules::ERROR_BELOW_BYTES - 1.0, Level::Error],
        'unknown' => [false, Level::Info],
    ]);

    it('makes an unwritable directory an error naming it', function () {
        $check = StorageRules::writable('Storage directory', '/srv/storage', false);

        expect($check->level)->toBe(Level::Error)
            ->and($check->message)->toContain('/srv/storage')
            ->and(StorageRules::writable('Storage directory', '/srv/storage', true)->level)->toBe(Level::Ok);
    });
});

describe('LogRules::recentErrors()', function () {
    it('warns about logged errors and sends the reader to the summary', function () {
        $check = LogRules::recentErrors(12, 24, true);

        expect($check->level)->toBe(Level::Warning)
            ->and($check->message)->toBe('12 error(s) logged in the last 24 hours.')
            ->and($check->fix)->toContain('stimmt_errors_summary');
    });

    it('says the count is a floor when the scan did not reach the start of the window', function () {
        expect(LogRules::recentErrors(500, 24, false)->message)->toContain('at least');
    });

    it('is fine without errors', function () {
        expect(LogRules::recentErrors(0, 24, true)->level)->toBe(Level::Ok);
    });
});
