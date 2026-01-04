<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\tools\McpTools;

describe('McpTools class structure', function () {
    it('has get_mcp_info tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(McpTools::class, 'getMcpInfo');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_mcp_info')
            ->and($instance->description)->toContain('information about the Craft MCP plugin');
    });

    it('has list_mcp_tools tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(McpTools::class, 'listMcpTools');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_mcp_tools')
            ->and($instance->description)->toContain('all available MCP tools');
    });

    it('has reload_mcp tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(McpTools::class, 'reloadMcp');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('reload_mcp')
            ->and($instance->description)->toContain('newly installed plugins');
    });

    it('getMcpInfo returns array', function () {
        $reflection = new ReflectionMethod(McpTools::class, 'getMcpInfo');
        $returnType = $reflection->getReturnType();

        expect($returnType?->getName())->toBe('array');
    });

    it('listMcpTools returns array', function () {
        $reflection = new ReflectionMethod(McpTools::class, 'listMcpTools');
        $returnType = $reflection->getReturnType();

        expect($returnType?->getName())->toBe('array');
    });
});

describe('McpTools tool count', function () {
    it('has exactly 3 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(McpTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(3);
    });
});
