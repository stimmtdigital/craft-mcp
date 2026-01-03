<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\tools\BackupTools;

describe('BackupTools class structure', function () {
    it('has list_backups tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(BackupTools::class, 'listBackups');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_backups')
            ->and($instance->description)->toContain('database backups');
    });

    it('has create_backup tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(BackupTools::class, 'createBackup');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('create_backup')
            ->and($instance->description)->toContain('dangerous');
    });

    it('all methods return array', function () {
        $methods = ['listBackups', 'createBackup'];

        foreach ($methods as $methodName) {
            $reflection = new ReflectionMethod(BackupTools::class, $methodName);
            expect($reflection->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('BackupTools dangerous tool', function () {
    it('create_backup is marked as dangerous', function () {
        expect(Mcp::DANGEROUS_TOOLS)->toContain('create_backup');
    });
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
