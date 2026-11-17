<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\operations\EnvironmentRules;
use stimmt\craft\Mcp\operations\PhpConstraint;

describe('EnvironmentRules::devMode()', function () {
    it('is an error in production, whatever the case of the word', function (string $environment) {
        $check = EnvironmentRules::devMode(true, $environment);

        expect($check->level)->toBe(Level::Error)->and($check->fix)->not->toBeNull();
    })->with([['production'], ['Production']]);

    it('is only information anywhere else, or when the environment is not set', function (?string $environment) {
        expect(EnvironmentRules::devMode(true, $environment)->level)->toBe(Level::Info);
    })->with([['dev'], ['staging'], ['prod'], [null]]);

    it('is fine when off', function () {
        expect(EnvironmentRules::devMode(false, 'production')->level)->toBe(Level::Ok);
    });
});

describe('EnvironmentRules::allowAdminChanges()', function () {
    it('warns when production allows admin changes', function () {
        expect(EnvironmentRules::allowAdminChanges(true, 'production')->level)->toBe(Level::Warning);
    });

    it('is fine when production disallows them, or outside production', function (bool $allowed, ?string $environment) {
        expect(EnvironmentRules::allowAdminChanges($allowed, $environment)->level)->toBe(Level::Ok);
    })->with([[false, 'production'], [true, 'dev'], [true, null]]);
});

describe('EnvironmentRules::php()', function () {
    it('judges the running version against the requirement', function (?string $constraint, string $version, Level $level) {
        expect(EnvironmentRules::php($constraint, $version)->level)->toBe($level);
    })->with([
        'meets it' => ['^8.2', '8.3.14', Level::Ok],
        'below it' => ['^8.2', '8.1.30', Level::Error],
        'no requirement found' => [null, '8.3.14', Level::Info],
        'a constraint it cannot read' => ['8.2.*', '8.3.14', Level::Info],
    ]);
});

describe('PhpConstraint::satisfies()', function () {
    it('reads the operators a "php" requirement is written with', function (string $constraint, string $version, ?bool $expected) {
        expect(PhpConstraint::satisfies($constraint, $version))->toBe($expected);
    })->with([
        ['^8.2', '8.2.0', true],
        ['^8.2', '8.4.1', true],
        ['^8.2', '9.0.0', false],
        ['^8.2', '8.1.99', false],
        ['~8.2', '8.5.0', true],
        ['>=8.2 <8.4', '8.3.9', true],
        ['>=8.2 <8.4', '8.4.0', false],
        ['>=8.2,<8.4', '8.4.0', false],
        ['^7.4 || ^8.1', '8.3.0', true],
        ['^7.4 | ^8.1', '8.0.0', false],
        // An exact pin is compared by Composer on four padded parts, which
        // version_compare does not do, so it is left undecided.
        ['8.2', '8.2.0', null],
        ['dev-main', '8.2.0', null],
        ['', '8.2.0', null],
    ]);
});

describe('EnvironmentRules::database()', function () {
    it('reports the driver and version without judging them', function () {
        $check = EnvironmentRules::database('MariaDB', '10.11.6');

        expect($check->level)->toBe(Level::Info)->and($check->message)->toBe('MariaDB 10.11.6.');
    });
});
