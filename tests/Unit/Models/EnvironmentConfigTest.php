<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use stimmt\craft\Mcp\Mcp;

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
