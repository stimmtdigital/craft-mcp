<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;

/**
 * Verdicts on Craft's queue: jobs that failed, and jobs nobody is running.
 */
final class QueueRules {
    /**
     * A waiting job older than this means nothing is running the queue. Craft
     * runs it on control panel requests or a worker, and either picks a job up
     * within seconds to minutes; an hour is well past both.
     */
    public const int STALE_AFTER_SECONDS = 3600;

    public static function failed(int $failed): Check {
        if ($failed === 0) {
            return new Check('Failed jobs', Level::Ok, 'None.');
        }

        return new Check(
            'Failed jobs',
            Level::Error,
            "{$failed} queue job(s) failed and will not run again on their own.",
            'Read why with stimmt_queue_failures, fix the cause, then retry them with Craft\'s queue_retry_all.',
        );
    }

    /**
     * @param int|null $oldestAge Seconds the oldest waiting job has been due, null when none waits
     */
    public static function waiting(int $waiting, ?int $oldestAge): Check {
        if ($waiting === 0 || $oldestAge === null) {
            return new Check('Waiting jobs', Level::Ok, 'None waiting.');
        }

        $age = self::duration($oldestAge);
        if ($oldestAge > self::STALE_AFTER_SECONDS) {
            return new Check(
                'Waiting jobs',
                Level::Warning,
                "{$waiting} waiting; the oldest has been due for {$age}, so the queue is not being run.",
                'Run a queue worker (`php craft queue/listen`) or a cron with `php craft queue/run`; Craft\'s queue_run '
                . 'clears the backlog once.',
            );
        }

        return new Check('Waiting jobs', Level::Ok, "{$waiting} waiting; the oldest has been due for {$age}.");
    }

    public static function uninspectable(string $driver): Check {
        return new Check('Queue', Level::Info, "Cannot inspect this queue driver ({$driver}); only Craft's database queue can be read.");
    }

    private static function duration(int $seconds): string {
        return match (true) {
            $seconds >= 86400 => intdiv($seconds, 86400) . ' day(s)',
            $seconds >= 3600 => intdiv($seconds, 3600) . ' hour(s)',
            $seconds >= 60 => intdiv($seconds, 60) . ' minute(s)',
            default => $seconds . ' second(s)',
        };
    }
}
