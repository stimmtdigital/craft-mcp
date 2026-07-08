<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use craft\models\Site;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\SiteResolver;

describe('SiteResolver', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        $site = new Site(['handle' => 'en']);
        Craft::$app = new class ($site) {
            public function __construct(private readonly Site $site) {
            }

            public function getSites(): object {
                $site = $this->site;

                return new class ($site) {
                    public function __construct(private readonly Site $site) {
                    }

                    public function getSiteByHandle(string $handle): ?Site {
                        return $handle === 'en' ? $this->site : null;
                    }
                };
            }
        };
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('returns null for null input', function () {
        expect(SiteResolver::resolve(null))->toBeNull();
    });

    it('resolves a known handle', function () {
        expect(SiteResolver::resolve('en')?->handle)->toBe('en');
    });

    it('throws on an unknown handle', function () {
        expect(fn () => SiteResolver::resolve('nope'))->toThrow(ToolCallException::class);
    });
});
