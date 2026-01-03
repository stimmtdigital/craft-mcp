<?php

declare(strict_types=1);

use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\tools\CommerceTools;

describe('CommerceTools class structure', function () {
    it('has isAvailable static method', function () {
        expect(method_exists(CommerceTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(CommerceTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and($reflection->getReturnType()?->getName())->toBe('bool');
    });

    it('has list_products tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listProducts');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_products')
            ->and($instance->description)->toContain('products');
    });

    it('has get_product tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'getProduct');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_product')
            ->and($instance->description)->toContain('product');
    });

    it('has list_orders tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listOrders');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_orders')
            ->and($instance->description)->toContain('orders');
    });

    it('has get_order tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'getOrder');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('get_order')
            ->and($instance->description)->toContain('order');
    });

    it('has list_order_statuses tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listOrderStatuses');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_order_statuses')
            ->and($instance->description)->toContain('order statuses');
    });

    it('has list_product_types tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listProductTypes');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('list_product_types')
            ->and($instance->description)->toContain('product types');
    });
});

describe('CommerceTools method signatures', function () {
    it('listProducts has optional type, limit and offset parameters', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listProducts');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(3);

        // All should be optional
        foreach ($parameters as $param) {
            expect($param->isOptional())->toBeTrue();
        }
    });

    it('getOrder accepts nullable id and number parameters', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'getOrder');
        $parameters = $reflection->getParameters();

        expect($parameters)->toHaveCount(2);

        expect($parameters[0]->getName())->toBe('id')
            ->and($parameters[0]->getType()?->allowsNull())->toBeTrue();

        expect($parameters[1]->getName())->toBe('number')
            ->and($parameters[1]->getType()?->allowsNull())->toBeTrue();
    });

    it('all methods return array', function () {
        $methods = ['listProducts', 'getProduct', 'listOrders', 'getOrder', 'listOrderStatuses', 'listProductTypes'];

        foreach ($methods as $methodName) {
            $reflection = new ReflectionMethod(CommerceTools::class, $methodName);
            expect($reflection->getReturnType()?->getName())->toBe('array');
        }
    });
});

describe('CommerceTools tool count', function () {
    it('has exactly 6 public methods with McpTool attribute', function () {
        $reflection = new ReflectionClass(CommerceTools::class);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $toolMethods = array_filter($methods, function ($method) {
            // Exclude isAvailable which is a utility method
            if ($method->getName() === 'isAvailable') {
                return false;
            }

            return !empty($method->getAttributes(McpTool::class));
        });

        expect($toolMethods)->toHaveCount(6);
    });
});
