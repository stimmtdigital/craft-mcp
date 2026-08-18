<?php

declare(strict_types=1);

use stimmt\craft\Mcp\http\SessionCache;
use Symfony\Component\Uid\Uuid;

describe('SessionCache', function () {
    beforeEach(function () {
        $this->id = Uuid::v4();
        $this->cache = new SessionCache();
    });

    it('loads a payload once and serves every read after it from memory', function () {
        $loads = 0;
        $load = function () use (&$loads): string|false {
            $loads++;

            return '{"a":1}';
        };

        expect($this->cache->remember($this->id, $load))->toBe('{"a":1}')
            ->and($this->cache->remember($this->id, $load))->toBe('{"a":1}')
            ->and($loads)->toBe(1);
    });

    // A missing row is an answer, not a cache miss: without this, the SELECT for
    // a session that does not exist yet is paid for again at every save point.
    it('remembers a missing row too', function () {
        $loads = 0;
        $load = function () use (&$loads): string|false {
            $loads++;

            return false;
        };

        expect($this->cache->remember($this->id, $load))->toBeFalse()
            ->and($this->cache->remember($this->id, $load))->toBeFalse()
            ->and($loads)->toBe(1);
    });

    it('keeps ids apart', function () {
        $other = Uuid::v4();

        expect($this->cache->remember($this->id, fn (): string|false => 'mine'))->toBe('mine')
            ->and($this->cache->remember($other, fn (): string|false => 'theirs'))->toBe('theirs');
    });

    // The distinction the write path depends on: having read a payload is not
    // having written it, so the first write of a request still has to land and
    // refresh dateUpdated.
    it('does not treat a read payload as written', function () {
        $this->cache->remember($this->id, fn (): string|false => '{"a":1}');

        expect($this->cache->isStored($this->id, '{"a":1}'))->toBeFalse();
    });

    it('reports a stored payload as stored, and a changed one as not', function () {
        $this->cache->store($this->id, '{"a":1}');

        expect($this->cache->isStored($this->id, '{"a":1}'))->toBeTrue()
            ->and($this->cache->isStored($this->id, '{"a":2}'))->toBeFalse();
    });

    it('serves a stored payload to a later read', function () {
        $this->cache->store($this->id, '{"a":1}');

        expect($this->cache->remember($this->id, fn (): string|false => 'should not load'))->toBe('{"a":1}');
    });

    it('forgets both the payload and the written mark', function () {
        $this->cache->store($this->id, '{"a":1}');
        $this->cache->forget($this->id);

        expect($this->cache->isStored($this->id, '{"a":1}'))->toBeFalse()
            ->and($this->cache->remember($this->id, fn (): string|false => 'reloaded'))->toBe('reloaded');
    });

    it('forgets several ids at once, and leaves the rest alone', function () {
        $second = Uuid::v4();
        $kept = Uuid::v4();
        $this->cache->store($this->id, 'a');
        $this->cache->store($second, 'b');
        $this->cache->store($kept, 'c');

        $this->cache->forget($this->id, $second);

        expect($this->cache->isStored($this->id, 'a'))->toBeFalse()
            ->and($this->cache->isStored($second, 'b'))->toBeFalse()
            ->and($this->cache->isStored($kept, 'c'))->toBeTrue();
    });
});
