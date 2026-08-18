<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use Mcp\Server\Session\SessionStoreInterface;
use stimmt\craft\Mcp\http\DbSessionStore;
use stimmt\craft\Mcp\http\SessionCache;
use Symfony\Component\Uid\Uuid;

it('implements the SDK session store contract', function () {
    expect(class_implements(DbSessionStore::class))->toHaveKey(SessionStoreInterface::class);
});

it('defaults the ttl to one hour', function () {
    $parameter = new ReflectionParameter([DbSessionStore::class, '__construct'], 'ttl');

    expect($parameter->getDefaultValue())->toBe(3600);
});

// The store's own queries need a database, which a unit test has none of. What
// can be proven without one is which calls reach it at all: Craft::$app is
// replaced by a sentinel whose getDb() throws, so "reached the table" and "did
// not reach the table" are both observable.
describe('DbSessionStore query traffic', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        Craft::$app = new class () {
            public function getDb(): never {
                throw new RuntimeException('reached the table');
            }
        };

        $this->id = Uuid::v4();
        $this->cache = new SessionCache();
        $this->store = new DbSessionStore(3600, $this->cache);
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('serves a read from the request cache', function () {
        $this->cache->store($this->id, '{"initialized":true}');

        expect($this->store->read($this->id))->toBe('{"initialized":true}');
    });

    // exists() used to be a SELECT of its own, immediately before the SELECT the
    // Session itself makes. Both ask whether there is a live row for this id.
    it('answers exists from the same payload as read', function () {
        $this->cache->store($this->id, '{"initialized":true}');

        expect($this->store->exists($this->id))->toBeTrue();
    });

    it('skips an upsert that would write back a payload it already wrote', function () {
        $this->cache->store($this->id, '{"initialized":true}');

        expect($this->store->write($this->id, '{"initialized":true}'))->toBeTrue();
    });

    it('upserts a changed payload', function () {
        $this->cache->store($this->id, '{"initialized":true}');

        expect(fn () => $this->store->write($this->id, '{"initialized":false}'))
            ->toThrow(RuntimeException::class, 'reached the table');
    });

    // dateUpdated is what keeps a session inside its TTL window, so the first
    // write of a request has to land even when nothing in the payload changed.
    it('upserts the first write of a request even when the payload is unchanged', function () {
        $this->cache->remember($this->id, fn (): string|false => '{"initialized":true}');

        expect(fn () => $this->store->write($this->id, '{"initialized":true}'))
            ->toThrow(RuntimeException::class, 'reached the table');
    });

    it('upserts again after the row was destroyed', function () {
        $this->cache->store($this->id, '{"initialized":true}');

        try {
            $this->store->destroy($this->id);
        } catch (RuntimeException) {
            // The delete reaches the sentinel; what is under test is the cache
            // invalidation that happens before it.
        }

        expect(fn () => $this->store->write($this->id, '{"initialized":true}'))
            ->toThrow(RuntimeException::class, 'reached the table');
    });
});
