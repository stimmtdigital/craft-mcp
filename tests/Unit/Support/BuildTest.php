<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Build;

/**
 * The version answers "which release" only when a release installed it. On a
 * branch install it is one constant string for every commit that branch will
 * ever have, which is why anything that must change when the code does keys on
 * the reference as well.
 */
it('reports the package version', function () {
    expect(Build::version())->toBeString()->not->toBe('');
});

it('shortens the commit reference, or reports none', function () {
    $reference = Build::reference();

    expect($reference === null || (is_string($reference) && strlen($reference) === 12))->toBeTrue();
});

it('combines both into a revision that changes when either does', function () {
    $revision = Build::revision();

    expect($revision)->toStartWith(Build::version());

    Build::reference() === null
        ? expect($revision)->toBe(Build::version())
        : expect($revision)->toBe(Build::version() . '@' . Build::reference());
});
