<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\operations\ProjectConfigRules;
use stimmt\craft\Mcp\operations\UpdateRules;

describe('UpdateRules', function () {
    it('makes pending migrations an error naming what is pending', function () {
        $check = UpdateRules::migrations(['craft', 'seomatic', 'content']);

        expect($check->level)->toBe(Level::Error)
            ->and($check->message)->toContain('craft, seomatic, content')
            ->and($check->fix)->toContain('php craft up');
    });

    it('is fine with no pending migrations', function () {
        expect(UpdateRules::migrations([])->level)->toBe(Level::Ok);
    });

    it('makes a database newer than the installed Craft an error', function () {
        expect(UpdateRules::schema(false)->level)->toBe(Level::Error)
            ->and(UpdateRules::schema(true)->level)->toBe(Level::Ok);
    });

    it('says it could not tell when Craft has no cached update info', function () {
        $check = UpdateRules::available(null);

        expect($check->level)->toBe(Level::Info)->and($check->message)->toContain('Could not determine');
    });

    it('warns about a critical update, names it, and only informs about the rest', function () {
        $critical = UpdateRules::available(['total' => 2, 'critical' => true, 'names' => ['craft', 'formie']]);
        $routine = UpdateRules::available(['total' => 1, 'critical' => false, 'names' => ['formie']]);
        $none = UpdateRules::available(['total' => 0, 'critical' => false, 'names' => []]);

        expect($critical->level)->toBe(Level::Warning)
            ->and($critical->message)->toContain('craft, formie')
            ->and($routine->level)->toBe(Level::Info)
            ->and($none->level)->toBe(Level::Ok);
    });
});

describe('ProjectConfigRules', function () {
    // The case that broke a real install: config/project written by newer
    // plugins than were deployed, so nothing could be applied.
    it('names every schema mismatch in one error, with the fix that resolves it', function () {
        $check = ProjectConfigRules::schema([
            ['cause' => 'Craft CMS', 'existing' => '5.8.0.3', 'incoming' => '5.9.0.0'],
            ['cause' => 'Formie', 'existing' => '3.0.1', 'incoming' => '3.1.0'],
        ]);

        expect($check->level)->toBe(Level::Error)
            ->and($check->message)->toContain('Craft CMS (installed 5.8.0.3, config/project 5.9.0.0)')
            ->and($check->message)->toContain('Formie (installed 3.0.1, config/project 3.1.0)')
            ->and($check->fix)->toBe('Commit config/project after updating, then run `php craft project-config/apply`.');
    });

    it('is fine with no mismatches', function () {
        expect(ProjectConfigRules::schema([])->level)->toBe(Level::Ok);
    });

    it('warns about YAML changes that are not applied', function (?int $pending, Level $level) {
        expect(ProjectConfigRules::pending($pending)->level)->toBe($level);
    })->with([
        'some pending' => [3, Level::Warning],
        'none pending' => [0, Level::Ok],
        'no config/project at all' => [null, Level::Info],
    ]);
});
