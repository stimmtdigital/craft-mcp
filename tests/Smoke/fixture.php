<?php

declare(strict_types=1);

/**
 * Installs the content model the smoke plan is written against.
 *
 *   php tests/Smoke/fixture.php     once per install, from anywhere Install reaches it
 *
 * The work happens in inside/fixture.php, piped into `php` in the Craft root
 * the same way the harness reaches the install for everything else (see
 * Install). This side only hands it the handles from Plan, so a handle is named
 * in exactly one place.
 *
 * WHY a script rather than a list of control-panel clicks: the baselines are
 * only comparable between installs that share this exact model, and a script
 * is the one description of it that cannot drift from what was clicked.
 */

namespace stimmt\craft\Mcp\Tests\Smoke;

use Throwable;

require_once __DIR__ . '/Redact.php';
require_once __DIR__ . '/Install.php';
require_once __DIR__ . '/Plan.php';

try {
    $answer = Install::script('fixture.php', [(string) json_encode(Plan::fixture())]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(1);
}

$report = is_array($answer['report'] ?? null) ? $answer['report'] : [];
fwrite(STDOUT, implode("\n", array_map('strval', $report)) . "\n");
