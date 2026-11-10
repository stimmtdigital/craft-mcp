<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\capabilities\CommerceTools;
use stimmt\craft\Mcp\integration\Capabilities;

/**
 * A capability class missing from the list is never registered with Craft,
 * so its tools silently do not exist; nothing errors. The list is therefore
 * checked against the directory, not against itself.
 */
$onDisk = static function (): array {
    $classes = array_map(
        static fn (string $file): string => 'stimmt\\craft\\Mcp\\capabilities\\' . basename($file, '.php'),
        (array) glob(dirname(__DIR__, 3) . '/src/capabilities/*.php'),
    );
    sort($classes);

    return $classes;
};

/** Stands in Craft's plugin service, answering only whether Commerce is on. */
$withCommerce = static function (bool $enabled): void {
    Craft::$app = new class ($enabled) {
        public function __construct(private readonly bool $enabled) {
        }

        public function getPlugins(): object {
            return new class ($this->enabled) {
                public function __construct(private readonly bool $enabled) {
                }

                public function isPluginEnabled(string $handle): bool {
                    return $handle === 'commerce' && $this->enabled;
                }
            };
        }
    };
};

describe('Capabilities::CORE', function () use ($onDisk) {
    it('lists every capability class except the conditional CommerceTools', function () use ($onDisk) {
        $core = Capabilities::CORE;
        sort($core);

        expect($core)->toBe(array_values(array_diff($onDisk(), [CommerceTools::class])));
    });

    it('lists only classes that exist', function () {
        expect(array_values(array_filter(Capabilities::CORE, static fn (string $class): bool => !class_exists($class))))->toBe([]);
    });

    it('never lists a class twice', function () {
        expect(array_unique(Capabilities::CORE))->toBe(Capabilities::CORE);
    });
});

// CommerceTools reads Commerce's own element classes; registering it without
// Commerce installed would make Craft reflect a class whose types do not exist.
describe('Capabilities::classes()', function () use ($withCommerce) {
    beforeEach(function () {
        $this->previousApp = Craft::$app;
    });

    afterEach(function () {
        Craft::$app = $this->previousApp;
    });

    it('registers the core classes alone while Commerce is off', function () use ($withCommerce) {
        $withCommerce(false);

        expect(Capabilities::classes())->toBe(Capabilities::CORE);
    });

    it('adds CommerceTools once Commerce is on', function () use ($withCommerce) {
        $withCommerce(true);

        expect(Capabilities::classes())->toBe([...Capabilities::CORE, CommerceTools::class]);
    });
});
