<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use stimmt\craft\Mcp\support\Psr16CacheAdapter;
use yii\caching\ArrayCache;

describe('Psr16CacheAdapter', function () {
    it('implements the PSR-16 CacheInterface', function () {
        expect(new Psr16CacheAdapter(new ArrayCache()))->toBeInstanceOf(SimpleCacheInterface::class);
    });

    it('exposes the discovery tag name used for scoped invalidation', function () {
        expect(Psr16CacheAdapter::TAG)->toBe('mcp-discovery');
    });

    it('round-trips falsy values without confusing them with a cache miss', function () {
        $adapter = new Psr16CacheAdapter(new ArrayCache());

        $adapter->set('flag', false);
        $adapter->set('zero', 0);
        $adapter->set('empty-string', '');
        $adapter->set('empty-array', []);
        $adapter->set('null-value', null);

        expect($adapter->get('flag', 'sentinel'))->toBeFalse()
            ->and($adapter->get('zero', 'sentinel'))->toBe(0)
            ->and($adapter->get('empty-string', 'sentinel'))->toBe('')
            ->and($adapter->get('empty-array', 'sentinel'))->toBe([])
            ->and($adapter->get('null-value', 'sentinel'))->toBeNull();
    });

    it('returns the given default on a genuine cache miss', function () {
        $adapter = new Psr16CacheAdapter(new ArrayCache());

        expect($adapter->get('missing', 'fallback'))->toBe('fallback')
            ->and($adapter->get('missing'))->toBeNull();
    });

    it('reports has() correctly even for a stored falsy value', function () {
        $adapter = new Psr16CacheAdapter(new ArrayCache());
        $adapter->set('flag', false);

        expect($adapter->has('flag'))->toBeTrue()
            ->and($adapter->has('missing'))->toBeFalse();
    });

    it('deletes a single key', function () {
        $adapter = new Psr16CacheAdapter(new ArrayCache());
        $adapter->set('key', 'value');

        $adapter->delete('key');

        expect($adapter->get('key', 'gone'))->toBe('gone');
    });

    it('round-trips getMultiple/setMultiple/deleteMultiple through the single-key methods', function () {
        $adapter = new Psr16CacheAdapter(new ArrayCache());

        $adapter->setMultiple(['a' => 1, 'b' => false]);
        $values = iterator_to_array($adapter->getMultiple(['a', 'b', 'c'], 'default'));

        expect($values)->toBe(['a' => 1, 'b' => false, 'c' => 'default']);

        $adapter->deleteMultiple(['a', 'b']);

        expect($adapter->get('a', 'gone'))->toBe('gone')
            ->and($adapter->get('b', 'gone'))->toBe('gone');
    });

    it('clear() invalidates only the tagged entries, never the wrapped cache itself', function () {
        $wrapped = new ArrayCache();
        $adapter = new Psr16CacheAdapter($wrapped);

        $adapter->set('discovery-key', ['tools' => ['a', 'b']]);
        // Written straight to the wrapped cache, bypassing the adapter and its
        // tag, to stand in for an unrelated key another plugin might store.
        $wrapped->set('unrelated-key', 'untouched');

        $adapter->clear();

        expect($adapter->get('discovery-key', 'gone'))->toBe('gone')
            ->and($wrapped->get('unrelated-key'))->toBe('untouched');
    });

    it('keeps entries from different prefixes on the same wrapped cache apart', function () {
        $wrapped = new ArrayCache();
        $first = new Psr16CacheAdapter($wrapped, 'first:');
        $second = new Psr16CacheAdapter($wrapped, 'second:');

        $first->set('key', 'from-first');
        $second->set('key', 'from-second');

        expect($first->get('key'))->toBe('from-first')
            ->and($second->get('key'))->toBe('from-second');
    });
});
