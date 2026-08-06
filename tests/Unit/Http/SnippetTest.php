<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\console\controllers\TokensController;
use stimmt\craft\Mcp\http\Snippet;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;

describe('Snippet', function () {
    it('exposes json() and url() as public static methods', function () {
        expect(class_exists(Snippet::class))->toBeTrue();

        $json = new ReflectionMethod(Snippet::class, 'json');
        $url = new ReflectionMethod(Snippet::class, 'url');

        expect($json->isStatic())->toBeTrue()
            ->and($json->isPublic())->toBeTrue()
            ->and($json->getReturnType()?->getName())->toBe('string')
            ->and(array_map(fn (ReflectionParameter $p): string => $p->getName(), $json->getParameters()))
                ->toBe(['plaintext', 'url'])
            ->and($url->isStatic())->toBeTrue()
            ->and($url->isPublic())->toBeTrue()
            ->and($url->getReturnType()?->getName())->toBe('string')
            ->and($url->getParameters())->toBe([]);
    });

    // #62: is composed via the gated method, not the raw json() builder,
    // so the console command never checks showClientConfigSnippet itself.
    it('is composed into the console controller output through the gated method', function () {
        $source = (string) file_get_contents((new ReflectionClass(TokensController::class))->getFileName());

        expect($source)->toContain('Snippet::jsonIfEnabled(')
            ->and($source)->toContain('Snippet::url(');
    });

    it('exposes jsonIfEnabled() as a public static method returning a nullable string', function () {
        $method = new ReflectionMethod(Snippet::class, 'jsonIfEnabled');

        expect($method->isStatic())->toBeTrue()
            ->and($method->isPublic())->toBeTrue()
            ->and($method->getReturnType()?->allowsNull())->toBeTrue()
            ->and(array_map(fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters()))
                ->toBe(['plaintext', 'url']);
    });

    describe('jsonIfEnabled', function () {
        it('builds the config snippet when showClientConfigSnippet is on (the default)', function () {
            expect(Snippet::jsonIfEnabled('plaintext-token', 'https://example.test/mcp'))
                ->toContain('plaintext-token')
                ->toContain('mcpServers');
        });

        // #62: single gate for every caller (CP reveal screen, console
        // command); this is the one place that must return null so neither
        // caller has to re-check the setting.
        it('returns null when showClientConfigSnippet is off', function () {
            $settingsProperty = new ReflectionProperty(Mcp::class, 'loadedSettings');
            $original = $settingsProperty->getValue();

            try {
                $settings = new Settings();
                $settings->showClientConfigSnippet = false;
                $settingsProperty->setValue(null, $settings);

                expect(Snippet::jsonIfEnabled('plaintext-token', 'https://example.test/mcp'))->toBeNull();
            } finally {
                $settingsProperty->setValue(null, $original);
            }
        });
    });
});
