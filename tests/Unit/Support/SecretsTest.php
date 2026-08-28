<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Secrets;

describe('Secrets::conceal() withholds credentials', function () {
    it('withholds a credential named by its key', function (string $key) {
        expect(Secrets::conceal($key, 'a-real-value'))->toBe(Secrets::PLACEHOLDER);
    })->with([
        'general.securityKey',
        'db.password',
        'db.dsn',
        'db.user',
        'custom.services.apiKey',
        'custom.mailer.transportSettings.password',
        'custom.vendor.client_secret',
        'custom.vendor.access-token',
    ]);

    it('withholds a connection url, whose name says nothing about credentials', function () {
        // Craft reads the user and the password back out of this one.
        expect(Secrets::conceal('db.url', 'mysql://root:hunter2@db:3306/craft'))
            ->toBe(Secrets::PLACEHOLDER);
    });

    it('withholds the whole structure under a credential key', function () {
        $value = ['user' => 'admin', 'pass' => 'hunter2'];

        expect(Secrets::conceal('custom.service.credentials', $value))->toBe(Secrets::PLACEHOLDER);
    });

    it('names the setting and says why the value is gone', function () {
        $result = Secrets::conceal('general.securityKey', 'a-real-value');

        expect($result)->toBeString()
            ->and($result)->toContain('redacted')
            ->and($result)->toContain('credential')
            ->and($result)->not->toContain('a-real-value');
    });
});

describe('Secrets::conceal() leaves harmless settings alone', function () {
    // Every one of these contains a credential word and holds nothing secret.
    // They are the reason the rule reads the last word of the key rather than
    // searching it for a substring.
    it('keeps a setting that merely contains a sensitive word', function (string $key, mixed $value) {
        expect(Secrets::conceal($key, $value))->toBe($value);
    })->with([
        ['general.setPasswordPath', 'setpassword'],
        ['general.setPasswordRequestPath', 'setpassword/request'],
        ['general.setPasswordSuccessPath', ''],
        ['general.useEmailAsUsername', true],
        ['general.csrfTokenName', 'CRAFT_CSRF_TOKEN'],
        ['general.tokenParam', 'token'],
        ['general.invalidUserTokenPath', 'login'],
        ['general.defaultTokenDuration', 86400],
        ['general.previewTokenDuration', null],
        ['general.storeUserIps', false],
        ['general.preventUserEnumeration', false],
        ['general.useSslOnTokenizedUrls', true],
        ['db.tablePrefix', 'craft_'],
        ['db.database', 'db'],
    ]);

    it('keeps the site token, which names a query string parameter', function () {
        expect(Secrets::conceal('general.siteToken', 'siteToken'))->toBe('siteToken');
    });

    it('keeps a toggle that is named after a credential without holding one', function () {
        // deferPublicRegistrationPassword is a bool. Withholding it would
        // report a secret that does not exist.
        expect(Secrets::conceal('general.deferPublicRegistrationPassword', false))->toBeFalse();
    });

    it('keeps an unset credential unset rather than claiming one is there', function () {
        expect(Secrets::conceal('general.securityKey', ''))->toBe('')
            ->and(Secrets::conceal('db.dsn', null))->toBeNull();
    });
});

describe('Secrets::conceal() walks a whole category', function () {
    it('judges each setting on its own full key path', function () {
        $general = [
            'devMode' => true,
            'securityKey' => 'a-real-value',
            'setPasswordPath' => 'setpassword',
            'siteToken' => 'siteToken',
        ];

        expect(Secrets::conceal('general', $general))->toBe([
            'devMode' => true,
            'securityKey' => Secrets::PLACEHOLDER,
            'setPasswordPath' => 'setpassword',
            'siteToken' => 'siteToken',
        ]);
    });

    it('reaches credentials nested inside a config file', function () {
        $file = [
            'default' => [
                'driver' => 'smtp',
                'username' => 'mailer@example.com',
                'password' => 'a-real-value',
            ],
        ];

        $result = Secrets::conceal('custom.mailer', $file);

        expect($result['default']['password'])->toBe(Secrets::PLACEHOLDER)
            ->and($result['default']['username'])->toBe(Secrets::PLACEHOLDER)
            ->and($result['default']['driver'])->toBe('smtp');
    });

    it('leaves a list of plain values alone', function () {
        expect(Secrets::conceal('general.allowedFileExtensions', ['jpg', 'png']))
            ->toBe(['jpg', 'png']);
    });
});

describe('Secrets against the real Craft config surface', function () {
    // The rule was designed against Craft's own property names rather than
    // against imagined ones, so the check stays runnable: every general and db
    // setting Craft declares, each carrying a value of its declared type, and
    // only the ones that genuinely hold a credential may be withheld. A new
    // Craft release that adds a credential, or renames one, fails here.
    $withheldIn = static function (string $class, string $category): array {
        $withheld = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            $value = match ($type instanceof ReflectionNamedType ? $type->getName() : 'string') {
                'bool' => true,
                'int' => 1,
                'float' => 1.0,
                'array' => ['x'],
                default => 'x',
            };

            if (!$property->isStatic() && Secrets::conceal("{$category}.{$property->getName()}", $value) === Secrets::PLACEHOLDER) {
                $withheld[] = $property->getName();
            }
        }

        sort($withheld);

        return $withheld;
    };

    it('withholds exactly the security key out of every general setting', function () use ($withheldIn) {
        expect($withheldIn(craft\config\GeneralConfig::class, 'general'))->toBe(['securityKey']);
    });

    it('withholds exactly the database credentials', function () use ($withheldIn) {
        expect($withheldIn(craft\config\DbConfig::class, 'db'))->toBe(['dsn', 'password', 'url', 'user']);
    });
});
