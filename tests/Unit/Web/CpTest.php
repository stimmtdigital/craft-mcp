<?php

declare(strict_types=1);

use stimmt\craft\Mcp\web\Cp;

// Craft and Twig are never booted in these tests: Cp::register() wires
// yii\base\Event handlers against Craft component classes, so calling it
// for real would require a booted Craft::$app. These assertions are
// structural instead: the registrar exposes the entry point Mcp::init()
// needs, and the source carries the exact wiring the plan specifies.
describe('web\Cp', function () {
    it('exposes a public static register() method', function () {
        $method = new ReflectionMethod(Cp::class, 'register');

        expect($method->isStatic())->toBeTrue()
            ->and($method->isPublic())->toBeTrue()
            ->and((string) $method->getReturnType())->toBe('void');
    });

    it('wires the mcp-tokens edit-user screen behind EVENT_DEFINE_EDIT_SCREENS', function () {
        $source = (string) file_get_contents((new ReflectionClass(Cp::class))->getFileName());

        expect($source)->toContain('EVENT_DEFINE_EDIT_SCREENS')
            ->and($source)->toContain("'mcp-tokens'")
            ->and($source)->toContain('getIsCurrent()');
    });

    it('registers the myaccount and per-user CP routes', function () {
        $source = (string) file_get_contents((new ReflectionClass(Cp::class))->getFileName());

        expect($source)->toContain('myaccount/mcp-tokens')
            ->and($source)->toContain('users/<userId:\d+>/mcp-tokens')
            ->and($source)->toContain('mcp/cp-tokens/index');
    });

    it('registers both MCP token user permissions', function () {
        $source = (string) file_get_contents((new ReflectionClass(Cp::class))->getFileName());

        expect($source)->toContain('EVENT_REGISTER_PERMISSIONS')
            ->and($source)->toContain('manageOwnMcpTokens')
            ->and($source)->toContain('manageAllMcpTokens');
    });

    it('leaves a marked insertion point for the Task 4 utility registration', function () {
        $source = (string) file_get_contents((new ReflectionClass(Cp::class))->getFileName());

        expect($source)->toContain('EVENT_REGISTER_UTILITIES')
            ->and($source)->toContain('Task 4 insertion point');
    });
});

describe('Mcp::init CP wiring', function () {
    it('calls Cp::register() from the plugin entry point', function () {
        $source = (string) file_get_contents((new ReflectionClass(\stimmt\craft\Mcp\Mcp::class))->getFileName());

        expect($source)->toContain('Cp::register()')
            ->and($source)->toContain('getIsCpRequest()')
            ->and($source)->toContain('httpTransport');
    });
});
