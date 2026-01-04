<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\tools\SiteTools;

describe('SiteTools class structure', function () {
    it('has list_sites tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(SiteTools::class, 'listSites');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_sites')
            ->and($instance->description)->toContain('all sites');
    });

    it('has get_site tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(SiteTools::class, 'getSite');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_site')
            ->and($instance->description)->toContain('specific site');
    });

    it('has list_site_groups tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(SiteTools::class, 'listSiteGroups');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_site_groups')
            ->and($instance->description)->toContain('site groups');
    });
});

describe('SiteTools method signatures', function () {
    it('getSite accepts nullable id, handle and context parameters', function () {
        $reflection = new ReflectionMethod(SiteTools::class, 'getSite');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(3);

        // Check id parameter
        expect($parameters[0]->getName())->toBe('id')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue()
            ->and($parameters[0]->getDefaultValue())->toBeNull();

        // Check handle parameter
        expect($parameters[1]->getName())->toBe('handle')
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue()
            ->and($parameters[1]->getDefaultValue())->toBeNull();
    });

    it('all methods return array', function () {
        $methods = ['listSites', 'getSite', 'listSiteGroups'];

        foreach ($methods as $methodName) {
            $reflection = new ReflectionMethod(SiteTools::class, $methodName);
            expect($reflection->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('SiteTools tool count', function () {
    it('has exactly 3 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(SiteTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(3);
    });
});
