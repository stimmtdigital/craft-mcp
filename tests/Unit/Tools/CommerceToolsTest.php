<?php

declare(strict_types=1);

use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\capabilities\CommerceTools;

describe('CommerceTools class structure', function () {
    it('has isAvailable static method', function () {
        expect(method_exists(CommerceTools::class, 'isAvailable'))->toBeTrue();

        $reflection = new ReflectionMethod(CommerceTools::class, 'isAvailable');
        expect($reflection->isStatic())->toBeTrue()
            ->and((string) $reflection->getReturnType())->toBe('bool');
    });

    it('has stimmt_commerce_products_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listProducts');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_commerce_products_list')
            ->and($instance->description)->toContain('products');
    });

    it('has stimmt_commerce_product_get tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'getProduct');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_commerce_product_get')
            ->and($instance->description)->toContain('product');
    });

    it('has stimmt_commerce_orders_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listOrders');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_commerce_orders_list')
            ->and($instance->description)->toContain('orders');
    });

    it('has stimmt_commerce_order_get tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'getOrder');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_commerce_order_get')
            ->and($instance->description)->toContain('order');
    });

    it('has stimmt_commerce_order_statuses_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listOrderStatuses');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_commerce_order_statuses_list')
            ->and($instance->description)->toContain('order statuses');
    });

    it('has stimmt_commerce_product_types_list tool with McpTool attribute', function () {
        $reflection = new ReflectionMethod(CommerceTools::class, 'listProductTypes');
        $attributes = $reflection->getAttributes(McpTool::class);

        expect($attributes)->toHaveCount(1);

        $instance = $attributes[0]->newInstance();
        expect($instance->name)->toBe('stimmt_commerce_product_types_list')
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
            expect((string) $reflection->getReturnType())->toBe('array');
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

// Orders and products carry customer and pricing data, so the reads that
// return them are admin-only; the status and type lists are configuration.
describe('CommerceTools admin gate', function () {
    it('keeps product and order reads admin-only', function (string $method) {
        expect((new ReflectionMethod(CommerceTools::class, $method))->getAttributes(RequiresAdmin::class))->toHaveCount(1);
    })->with(['listProducts', 'getProduct', 'listOrders', 'getOrder']);
});
