<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use Throwable;

/**
 * Wire level smoke harness.
 *
 *   composer smoke                        every profile, compared to its baseline
 *   composer smoke -- --profile=http-full one profile
 *   composer smoke:baseline               re-record after an intended change
 *   composer smoke:heavy                  include create_backup and anything else slow
 *
 * Exit code is 0 only when there is no drift, no unexpected failure and no
 * scope violation, on every profile that ran. Run it before and after each
 * refactor phase: the whole promise of those phases is that structure changes
 * and behaviour does not, and this is the only thing in the repo that can hold
 * us to that.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/StdioClient.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/Credentials.php';
require_once __DIR__ . '/Profile.php';
require_once __DIR__ . '/Shape.php';
require_once __DIR__ . '/Assert.php';
require_once __DIR__ . '/Expectations.php';
require_once __DIR__ . '/Plan.php';
require_once __DIR__ . '/Boundary.php';
require_once __DIR__ . '/Runner.php';
require_once __DIR__ . '/Harness.php';
require_once __DIR__ . '/Report.php';

$options = getopt('', ['baseline', 'heavy', 'server:', 'profile:']);
$writeBaseline = array_key_exists('baseline', $options);
$serverCommand = is_string($options['server'] ?? null)
    ? $options['server']
    : 'php ' . dirname(__DIR__, 2) . '/bin/mcp-server';

try {
    $profiles = selectedProfiles($options['profile'] ?? null);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(2);
}

$harness = new Harness($serverCommand, array_key_exists('heavy', $options));
$snapshots = [];
$exitCode = 0;

foreach ($profiles as $profile) {
    fwrite(STDOUT, "== {$profile->name} ({$profile->transport}, scope " . ($profile->scope ?? 'none') . ") ==\n");

    try {
        $snapshot = $harness->run($profile);
    } catch (Throwable $exception) {
        // A profile that cannot connect is a finding, not a reason to record an
        // empty baseline over the one that already works.
        fwrite(STDOUT, '  UNREACHABLE ' . $exception->getMessage() . "\n\n");
        $exitCode = 1;

        continue;
    }

    $snapshots[$profile->name] = $snapshot;
    $exitCode = max($exitCode, settle($profile, $snapshot, $writeBaseline));
    fwrite(STDOUT, "\n");
}

foreach (Report::ordering($snapshots) as $violation) {
    fwrite(STDOUT, "FAIL {$violation}\n");
    $exitCode = 1;
}

exit($exitCode);

/**
 * @return list<Profile>
 */
function selectedProfiles(mixed $requested): array {
    if ($requested === null) {
        return array_values(Profile::all());
    }

    $names = is_array($requested) ? $requested : [$requested];

    return array_map(static fn (mixed $name): Profile => Profile::named((string) $name), $names);
}

/**
 * Records or compares one profile's snapshot and returns its exit code.
 *
 * @param array<string, mixed> $snapshot
 */
function settle(Profile $profile, array $snapshot, bool $writeBaseline): int {
    $path = __DIR__ . '/baseline/' . $profile->baseline;
    fwrite(STDOUT, Report::summary($snapshot));

    if ($writeBaseline) {
        return record($path, $snapshot);
    }

    $baseline = readBaseline($path);

    return $baseline === null ? 2 : compare($baseline, $snapshot);
}

/**
 * @return array<string, mixed>|null
 */
function readBaseline(string $path): ?array {
    if (!is_file($path)) {
        fwrite(STDERR, "No baseline at {$path}. Record one with --baseline first.\n");

        return null;
    }

    $baseline = json_decode((string) file_get_contents($path), true);
    if (!is_array($baseline)) {
        fwrite(STDERR, "Baseline at {$path} is not readable JSON.\n");

        return null;
    }

    return $baseline;
}

/**
 * @param array<string, mixed> $snapshot
 */
function record(string $path, array $snapshot): int {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Could not create {$directory}\n");

        return 2;
    }

    file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, "Baseline written to {$path}\n");

    return Report::failureCount($snapshot) > 0 ? 1 : 0;
}

/**
 * @param array<string, mixed> $baseline
 * @param array<string, mixed> $snapshot
 */
function compare(array $baseline, array $snapshot): int {
    $differences = Report::diff($baseline, $snapshot);

    if ($differences === []) {
        fwrite(STDOUT, "No drift against the baseline.\n");

        return Report::failureCount($snapshot) > 0 ? 1 : 0;
    }

    fwrite(STDOUT, 'Drift against the baseline (' . count($differences) . "):\n");
    foreach ($differences as $difference) {
        fwrite(STDOUT, "  {$difference}\n");
    }

    return 1;
}
