<?php

declare(strict_types=1);

/**
 * Cut a release: turn the CHANGELOG's Unreleased section into a versioned
 * section, commit, tag, and push in one go.
 *
 * Usage (from the repo root):
 *   composer cut -- v1.4.0-beta.2            # pre-release
 *   composer cut -- v1.4.0                   # stable
 *   composer cut -- v1.4.0-beta.2 --dry-run  # show what would happen
 *
 * What it does:
 *   1. Guards: valid tag, clean tree, on master, tag not taken, Unreleased
 *      section not empty.
 *   2. Retitles `## [Unreleased]` to `## [<version>] - <today>` and opens a
 *      fresh empty `## [Unreleased]` above it.
 *   3. Commits (`docs: changelog for <tag>`), tags, pushes commit and tag.
 *      The Release workflow does the rest (pre-release tags publish
 *      immediately; stable tags create a draft for a title pass).
 *
 * Stable releases after a beta run: the beta sections stay in the file as
 * history; consolidating them under the stable heading is an editorial call,
 * not this script's.
 *
 * @author Max van Essen <support@stimmt.digital>
 */

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$tag = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? null;

function fail(string $message): never {
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

function run(string $command): string {
    exec($command . ' 2>&1', $output, $code);
    $text = implode("\n", $output);
    if ($code !== 0) {
        fail("`{$command}` failed:\n{$text}");
    }

    return $text;
}

if ($tag === null || !preg_match('/^v\d+\.\d+\.\d+(-(alpha|beta|rc)\.\d+)?$/', $tag)) {
    fail('pass a tag like v1.4.0 or v1.4.0-beta.2 (usage: composer cut -- <tag> [--dry-run])');
}

$changelogPath = dirname(__DIR__, 2) . '/CHANGELOG.md';
$changelog = file_get_contents($changelogPath);
if ($changelog === false) {
    fail('CHANGELOG.md not found');
}

$version = substr($tag, 1);
if (str_contains($changelog, "## [{$version}]")) {
    fail("CHANGELOG.md already has a section for {$version}");
}

if (!preg_match('/## \[Unreleased\]\n(.*?)(?=\n## \[|\z)/s', $changelog, $match)) {
    fail('no ## [Unreleased] section found');
}

if (trim($match[1]) === '') {
    fail('the Unreleased section is empty; nothing to release');
}

if (run('git rev-parse --abbrev-ref HEAD') !== 'master') {
    fail('cut releases from master');
}

if (run('git status --porcelain') !== '') {
    fail('working tree is not clean');
}

if (run("git tag --list {$tag}") !== '') {
    fail("tag {$tag} already exists");
}

$today = date('Y-m-d');
$updated = str_replace(
    "## [Unreleased]\n",
    "## [Unreleased]\n\n## [{$version}] - {$today}\n",
    $changelog,
);

if ($dryRun) {
    echo "dry run: would retitle Unreleased to [{$version}] - {$today}, then:\n";
    echo "  git commit -m \"docs: changelog for {$tag}\" CHANGELOG.md\n";
    echo "  git tag {$tag} && git push origin master {$tag}\n\n";
    echo "CHANGELOG head after the edit:\n";
    echo implode("\n", array_slice(explode("\n", $updated), 0, 16)) . "\n";
    exit(0);
}

file_put_contents($changelogPath, $updated);
run('git add CHANGELOG.md');
run("git commit -m \"docs: changelog for {$tag}\"");
run("git tag {$tag}");
run("git push origin master {$tag}");

echo "{$tag} cut: changelog committed, tag pushed, the Release workflow takes it from here.\n";
echo str_contains($tag, '-')
    ? "Pre-release: it will publish itself; give it a title with `gh release edit {$tag} --title \"...\"`.\n"
    : "Stable: a DRAFT release will appear; polish the title, then publish.\n";
