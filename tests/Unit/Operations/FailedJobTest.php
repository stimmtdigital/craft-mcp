<?php

declare(strict_types=1);

use stimmt\craft\Mcp\operations\FailedJob;

describe('FailedJob::present()', function () {
    it('shapes a queue row with ISO dates, the class and the error', function () {
        $job = FailedJob::present([
            'id' => '42',
            'description' => 'Generating pending image transforms',
            'timePushed' => '1763373600',
            'attempt' => '3',
            'dateFailed' => '2026-11-17 10:05:00',
            'error' => 'cURL error 28: timed out',
        ], 'craft\queue\jobs\GeneratePendingTransforms');

        expect($job)->toBe([
            'id' => 42,
            'class' => 'craft\queue\jobs\GeneratePendingTransforms',
            'description' => 'Generating pending image transforms',
            'attempt' => 3,
            'timePushed' => '2025-11-17T10:00:00+00:00',
            'failedAt' => '2026-11-17T10:05:00+00:00',
            'error' => 'cURL error 28: timed out',
        ]);
    });

    // The serialized job can hold anything a plugin pushed, a token included.
    it('never passes the job payload or any other column through', function () {
        $job = FailedJob::present(['id' => 1, 'job' => 'O:8:"stdClass":1:{s:5:"token";s:6:"secret";}', 'channel' => 'queue'], null);

        expect(array_keys($job))->toBe(['id', 'class', 'description', 'attempt', 'timePushed', 'failedAt', 'error'])
            ->and(json_encode($job))->not->toContain('secret');
    });

    it('caps a long error and answers null for what is missing', function () {
        $job = FailedJob::present(['id' => 1, 'error' => str_repeat('e', 5000), 'description' => ''], null);

        expect(mb_strlen((string) $job['error']))->toBe(FailedJob::ERROR_LENGTH)
            ->and($job['description'])->toBeNull()
            ->and($job['timePushed'])->toBeNull()
            ->and($job['failedAt'])->toBeNull();
    });
});
