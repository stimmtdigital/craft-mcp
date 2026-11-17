<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integration\Check;
use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\operations\HealthCheck;
use stimmt\craft\Mcp\operations\HealthReport;

/**
 * The report answers "what do I do first", so errors lead, and among equals
 * the gathering order (area by area) is kept.
 */
$check = static fn (string $area, string $label, Level $level, ?string $fix = null): HealthCheck => new HealthCheck(
    $area,
    new Check($label, $level, "{$label} message", $fix),
);

describe('HealthReport::build()', function () use ($check) {
    it('puts errors first, then warnings, info and ok, keeping the order among equals', function () use ($check) {
        $report = HealthReport::build([
            $check('mcp', 'a', Level::Ok),
            $check('queue', 'b', Level::Warning),
            $check('mcp', 'c', Level::Error),
            $check('storage', 'd', Level::Info),
            $check('queue', 'e', Level::Error),
            $check('errors', 'f', Level::Warning),
        ]);

        expect(array_column($report['checks'], 'label'))->toBe(['c', 'e', 'b', 'f', 'd', 'a']);
    });

    it('counts every level, zero included', function () use ($check) {
        $report = HealthReport::build([$check('mcp', 'a', Level::Error), $check('mcp', 'b', Level::Error)]);

        expect($report['summary'])->toBe(['ok' => 0, 'info' => 0, 'warning' => 0, 'error' => 2]);
    });

    it('files each check under its area and carries a fix only where there is one', function () use ($check) {
        $report = HealthReport::build([
            $check('queue', 'Failed jobs', Level::Error, 'Retry them'),
            $check('storage', 'Free disk space', Level::Ok),
        ]);

        expect($report['checks'][0])->toBe([
            'area' => 'queue',
            'label' => 'Failed jobs',
            'level' => 'error',
            'message' => 'Failed jobs message',
            'fix' => 'Retry them',
        ])->and($report['checks'][1])->not->toHaveKey('fix');
    });
});
