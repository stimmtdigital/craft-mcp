<?php

declare(strict_types=1);

use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\capabilities\ActivityTools;
use stimmt\craft\Mcp\capabilities\DependencyTools;
use stimmt\craft\Mcp\capabilities\ProfilingTools;

/**
 * What Craft reads off the three insight tools before anything runs: who may
 * call them and what they declare about their reach.
 */
$tool = static fn (string $class, string $method): McpTool => (new ReflectionMethod($class, $method))
    ->getAttributes(McpTool::class)[0]->newInstance();

$isAdminOnly = static fn (string $class, string $method): bool => (new ReflectionMethod($class, $method))
    ->getAttributes(RequiresAdmin::class) !== [];

describe('the insight tools', function () use ($tool, $isAdminOnly) {
    it('keeps the activity feed open to editors, who get only what they may view', function () use ($tool, $isAdminOnly) {
        expect($tool(ActivityTools::class, 'recentActivity')->name)->toBe('stimmt_activity_recent')
            ->and($isAdminOnly(ActivityTools::class, 'recentActivity'))->toBeFalse();
    });

    it('keeps the audit and the request profile to admins', function () use ($isAdminOnly) {
        expect($isAdminOnly(DependencyTools::class, 'auditDependencies'))->toBeTrue()
            ->and($isAdminOnly(ProfilingTools::class, 'slowRequests'))->toBeTrue();
    });

    // The audit is the one tool here that talks to a third party; a client
    // gates on openWorldHint, and the person approving it reads the description.
    it('declares the audit open-world and says what it sends where', function () use ($tool) {
        $audit = $tool(DependencyTools::class, 'auditDependencies');

        expect($audit->name)->toBe('stimmt_dependencies_audit')
            ->and($audit->annotations?->openWorldHint)->toBeTrue()
            ->and($audit->annotations?->readOnlyHint)->toBeTrue()
            ->and($audit->description)->toContain('SENDS THE LIST OF INSTALLED PACKAGE NAMES')
            ->and($audit->description)->toContain('packagist.org');
    });

    it('marks the activity feed and the request profile closed-world reads', function () use ($tool) {
        foreach ([[ActivityTools::class, 'recentActivity'], [ProfilingTools::class, 'slowRequests']] as [$class, $method]) {
            $annotations = $tool($class, $method)->annotations;

            expect($annotations?->readOnlyHint)->toBeTrue()
                ->and($annotations?->openWorldHint)->not->toBeTrue();
        }
    });
});
