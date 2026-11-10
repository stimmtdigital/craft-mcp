<?php

declare(strict_types=1);

/**
 * Prove this checkout installs next to the newest stable Craft release.
 *
 * Usage (from the repo root):
 *   php .github/scripts/latest-craft.php
 *
 * Builds a throwaway Craft project that requires the latest stable
 * craftcms/cms of every major the plugin supports, plus this checkout through
 * a path repository, and asks Composer to resolve it. Exits non-zero, with
 * Composer's explanation, when it cannot.
 *
 * WHY: craftcms/cms requires mcp/sdk itself. Craft 5.12.0 pinned it to 0.8.1
 * while 1.8.x needed ^0.9, so the newest plugin could not be installed on the
 * newest Craft (#78). The test jobs never saw it: with no lock file, Composer
 * quietly settled on an older Craft and everything stayed green. Resolving
 * against an explicitly pinned latest Craft takes that way out away.
 */

function fail(string $message): never {
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

/** @return array{int, string} exit code and output, stderr included unless $stdoutOnly */
function composer(string $args, string $cwd, bool $stdoutOnly = false): array {
    $redirect = $stdoutOnly ? ' 2>/dev/null' : ' 2>&1';
    exec('cd ' . escapeshellarg($cwd) . ' && composer ' . $args . ' --no-interaction' . $redirect, $output, $code);

    return [$code, implode("\n", $output)];
}

$root = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($root . '/composer.json'), true);
$constraint = $manifest['require']['craftcms/cms'] ?? fail('composer.json does not require craftcms/cms');

preg_match_all('/\^(\d+)/', $constraint, $majors);
if ($majors[1] === []) {
    fail("cannot read the supported Craft majors from \"{$constraint}\"");
}

// stdout only: Composer prints a notice on stderr that would break the JSON.
[, $json] = composer('show craftcms/cms --all --format=json', sys_get_temp_dir(), true);
$versions = json_decode($json, true)['versions'] ?? fail('could not list craftcms/cms versions: ' . substr($json, 0, 300));

$failed = false;
foreach (array_unique($majors[1]) as $major) {
    // Stable releases only: Craft numbers them 5.12.1 and 5.12.0.1.
    $stable = array_filter($versions, static fn (string $v): bool => preg_match('/^' . $major . '(\.\d+){2,3}$/', $v) === 1);
    if ($stable === []) {
        fail("no stable craftcms/cms {$major}.x release found");
    }
    usort($stable, 'version_compare');
    $latest = end($stable);

    $project = sys_get_temp_dir() . '/craft-mcp-latest-craft-' . getmypid() . '-' . $major;
    @mkdir($project, 0o777, true);
    file_put_contents($project . '/composer.json', json_encode([
        'require' => ['craftcms/cms' => $latest, 'stimmt/craft-mcp' => '*@dev'],
        'repositories' => [['type' => 'path', 'url' => $root]],
        'prefer-stable' => true,
        'config' => ['allow-plugins' => ['craftcms/plugin-installer' => true, 'yiisoft/yii2-composer' => true]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    [$code, $output] = composer('update --dry-run --no-progress', $project);
    array_map('unlink', glob($project . '/*') ?: []);
    rmdir($project);
    if ($code === 0) {
        echo "ok: installs on craftcms/cms {$latest}\n";

        continue;
    }

    $failed = true;
    fwrite(STDERR, "FAIL: does not install on craftcms/cms {$latest}, the newest stable Craft {$major}:\n\n{$output}\n\n");
}

exit($failed ? 1 : 0);
