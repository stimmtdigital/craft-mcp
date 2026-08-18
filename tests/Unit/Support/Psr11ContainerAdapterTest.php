<?php

declare(strict_types=1);

// RealCraft is required for Yii itself (it loads Yii.php unconditionally), which
// is what makes a real yii\di\Container constructible here. Craft may already be
// the stub by the time this file runs; both carry a $container property, so the
// assignment below works either way.
require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\support\Psr11ContainerAdapter;
use stimmt\craft\Mcp\support\ServiceNotFoundException;
use yii\di\Container;

describe('Psr11ContainerAdapter', function () {
    beforeEach(function () {
        $this->originalContainer = Craft::$container;
        $this->originalApp = Craft::$app;

        Craft::$container = new Container();
        // A service locator that claims a class name as one of its component
        // ids, which is the collision the adapter has to refuse to answer.
        Craft::$app = new class () {
            public function has(string $id): bool {
                return $id === DateTimeImmutable::class || $id === 'db';
            }

            public function get(string $id): string {
                return "component:{$id}";
            }
        };

        $this->adapter = new Psr11ContainerAdapter();
    });

    afterEach(function () {
        Craft::$container = $this->originalContainer;
        Craft::$app = $this->originalApp;
    });

    // The SDK asks this container for a handler's own class name and uses
    // whatever comes back in place of the class. A service locator hit on that
    // name would hand it a Craft component instead of the tool.
    it('does not answer for a class name the container does not define', function () {
        expect($this->adapter->has(DateTimeImmutable::class))->toBeFalse()
            ->and(fn () => $this->adapter->get(DateTimeImmutable::class))
                ->toThrow(ServiceNotFoundException::class);
    });

    it('resolves a class name the container does define', function () {
        Craft::$container->set(DateTimeImmutable::class, fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-18'));

        expect($this->adapter->has(DateTimeImmutable::class))->toBeTrue()
            ->and($this->adapter->get(DateTimeImmutable::class))->toBeInstanceOf(DateTimeImmutable::class);
    });

    it('still resolves a plain component id through the service locator', function () {
        expect($this->adapter->has('db'))->toBeTrue()
            ->and($this->adapter->get('db'))->toBe('component:db');
    });

    it('reports an unknown id as unknown', function () {
        expect($this->adapter->has('nothing-here'))->toBeFalse()
            ->and(fn () => $this->adapter->get('nothing-here'))->toThrow(ServiceNotFoundException::class);
    });
});
