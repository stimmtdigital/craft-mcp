<?php

declare(strict_types=1);

use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\Dangerous;
use stimmt\craft\Mcp\capabilities\BackupTools;

describe('BackupTools class structure', function () {
    it('has stimmt_backups_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(BackupTools::class, 'listBackups');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_backups_list')
            ->and($instance->description)->toContain('database backups');
    });

    it('has stimmt_backups_create tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(BackupTools::class, 'createBackup');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_backups_create')
            ->and($instance->description)->toContain('dangerous');
    });

    it('all methods return array', function () {
        $methods = ['listBackups', 'createBackup'];

        foreach ($methods as $methodName) {
            $reflection = new ReflectionMethod(BackupTools::class, $methodName);
            expect((string) $reflection->getReturnType())->toBe('array');
        }
    });
});

describe('BackupTools gates', function () {
    // A backup writes a full database dump to the server's disk, so it is
    // hidden whenever enableDangerousTools is off (production by default).
    it('marks stimmt_backups_create dangerous and leaves the listing alone', function () {
        expect((new ReflectionMethod(BackupTools::class, 'createBackup'))->getAttributes(Dangerous::class))->toHaveCount(1)
            ->and((new ReflectionMethod(BackupTools::class, 'listBackups'))->getAttributes(Dangerous::class))->toBe([]);
    });

    it('keeps both tools admin-only', function (string $method) {
        expect((new ReflectionMethod(BackupTools::class, $method))->getAttributes(RequiresAdmin::class))->toHaveCount(1);
    })->with(['listBackups', 'createBackup']);
});

describe('BackupTools tool count', function () {
    it('has exactly 2 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(BackupTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(2);
    });
});
