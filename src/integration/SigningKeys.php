<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

use Closure;
use Craft;
use craft\helpers\App;
use Throwable;

/**
 * Makes sure Craft can sign OAuth access tokens, so an MCP client can finish
 * connecting without anyone running `php craft oauth/generate-keys` by hand.
 *
 * Craft never creates the key pair itself: until it exists, every connection
 * fails at the moment the token is issued, after the user has already logged
 * in and approved. The plugin's migrations call this, so a `craft up` on
 * deploy provisions the keys on the server that runs it.
 *
 * It never replaces keys: new keys invalidate every token issued with the old
 * ones. Configured keys (the `privateKey`/`publicKey` OAuth settings) and a
 * half-present pair are reported, not touched.
 */
final readonly class SigningKeys {
    public const string PRESENT = 'present';

    public const string GENERATED = 'generated';

    public const string CONFIGURED_INVALID = 'configured-invalid';

    public const string PARTIAL = 'partial';

    public const string FAILED = 'failed';

    /**
     * @param Closure(): bool $hasKeys whether Craft can load a valid pair
     * @param Closure(): bool $configured whether privateKey or publicKey is set in config
     * @param Closure(): bool $anyFileExists whether either default key file exists in storage/
     * @param Closure(): void $generate writes a new pair to storage/
     */
    public function __construct(
        private Closure $hasKeys,
        private Closure $configured,
        private Closure $anyFileExists,
        private Closure $generate,
    ) {
    }

    public static function forCraft(): self {
        $oauth = Craft::$app->getOAuth();
        $storage = Craft::$app->getPath()->getStoragePath();

        return new self(
            hasKeys: static fn (): bool => $oauth->hasKeys(),
            configured: static function (): bool {
                $config = Craft::$app->getConfig()->getOAuth();

                return (string) App::parseEnv($config->privateKey) !== '' || (string) App::parseEnv($config->publicKey) !== '';
            },
            anyFileExists: static fn (): bool => file_exists("{$storage}/oauth-private.key") || file_exists("{$storage}/oauth-public.key"),
            generate: static function () use ($oauth): void {
                $oauth->generateKeys();
            },
        );
    }

    /**
     * One of the class constants, saying what happened.
     */
    public function ensure(): string {
        if (($this->hasKeys)()) {
            return self::PRESENT;
        }

        if (($this->configured)()) {
            return self::CONFIGURED_INVALID;
        }

        if (($this->anyFileExists)()) {
            return self::PARTIAL;
        }

        try {
            ($this->generate)();
        } catch (Throwable) {
            return self::FAILED;
        }

        return ($this->hasKeys)() ? self::GENERATED : self::FAILED;
    }

    /**
     * The line a migration prints for an outcome.
     */
    public static function describe(string $outcome): string {
        return match ($outcome) {
            self::PRESENT => 'Craft OAuth signing keys: already in place.',
            self::GENERATED => 'Craft OAuth signing keys: generated in storage/. Keep storage/ across deploys, and give every server of this environment the same pair.',
            self::CONFIGURED_INVALID => 'Craft OAuth signing keys: the privateKey/publicKey OAuth settings are set but do not load. Fix them; nothing was generated.',
            self::PARTIAL => 'Craft OAuth signing keys: only one of storage/oauth-private.key and oauth-public.key exists. Not replaced, since that would invalidate existing logins; run `php craft oauth/generate-keys --force` if no client is connected.',
            default => 'Craft OAuth signing keys: could not be generated. Run `php craft oauth/generate-keys`; MCP clients cannot connect until the keys exist.',
        };
    }
}
