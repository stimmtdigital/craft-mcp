<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Fixtures/RealCraft.php';

use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\models\Settings;

describe('Mcp::resolveEnvironmentConfig', function () {
    it('leaves flat configs untouched', function () {
        $config = ['enabled' => true, 'logLevel' => 'debug'];

        expect(Mcp::resolveEnvironmentConfig($config, 'production'))->toBe($config);
    });

    it('merges the star base with the current environment block', function () {
        $config = [
            '*' => ['enabled' => true, 'logLevel' => 'error'],
            'dev' => ['logLevel' => 'debug', 'enableDangerousTools' => true],
            'production' => ['enableDangerousTools' => false],
        ];

        expect(Mcp::resolveEnvironmentConfig($config, 'dev'))
            ->toBe(['enabled' => true, 'logLevel' => 'debug', 'enableDangerousTools' => true])
            ->and(Mcp::resolveEnvironmentConfig($config, 'production'))
            ->toBe(['enabled' => true, 'logLevel' => 'error', 'enableDangerousTools' => false]);
    });

    it('falls back to the star base alone for unknown environments', function () {
        $config = ['*' => ['enabled' => true], 'dev' => ['logLevel' => 'debug']];

        expect(Mcp::resolveEnvironmentConfig($config, 'staging'))->toBe(['enabled' => true]);
    });
});

/**
 * The defaults production applies before config/craft-mcp.php is read. v1
 * also switched the whole plugin off there; v2 does not, because Craft's MCP
 * server being on in production is already an admin's explicit choice and the
 * content tools are as safe there as Craft's own.
 */
describe('Mcp production defaults', function () {
    $apply = static function (string $environment): Settings {
        Craft::$app = new class ($environment) {
            public function __construct(public string $env) {
            }
        };

        return (new ReflectionMethod(Mcp::class, 'applyProductionDefaults'))->invoke(null, new Settings());
    };

    beforeEach(function () {
        $this->previousApp = Craft::$app;
    });

    afterEach(function () {
        Craft::$app = $this->previousApp;
    });

    it('forces the dangerous tools off in production and leaves the plugin on', function () use ($apply) {
        $settings = $apply('production');

        expect($settings->enableDangerousTools)->toBeFalse()
            ->and($settings->enabled)->toBeTrue();
    });

    it('leaves every default alone outside production', function () use ($apply) {
        $settings = $apply('dev');

        expect($settings->enableDangerousTools)->toBeTrue()
            ->and($settings->enabled)->toBeTrue();
    });
});
