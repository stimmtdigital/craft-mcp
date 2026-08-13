<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';
require_once dirname(__DIR__, 2) . '/Fixtures/RecordingCache.php';

use stimmt\craft\Mcp\support\DiscoveryCache;
use stimmt\craft\Mcp\support\Psr16CacheAdapter;
use stimmt\craft\Mcp\Tests\Fixtures\RecordingCache;
use yii\caching\ArrayCache;
use yii\caching\TagDependency;

beforeEach(function () {
    $this->source = sys_get_temp_dir() . '/mcp-discovery-' . uniqid();
    mkdir($this->source . '/tools', 0o777, true);
    file_put_contents($this->source . '/tools/EntryTools.php', '<?php // entries');

    $this->wrapped = new ArrayCache();
});

afterEach(function () {
    array_map('unlink', glob($this->source . '/tools/*') ?: []);
    rmdir($this->source . '/tools');
    rmdir($this->source);
});

describe('DiscoveryCache in devMode', function () {
    it('serves the cached scan while the code is untouched', function () {
        $cache = new DiscoveryCache($this->wrapped, devMode: true, version: '1.0.0');

        $cache->of($this->source)->set('discovery', ['tools' => ['get_entry']]);

        expect($cache->of($this->source)->get('discovery', 'miss'))
            ->toBe(['tools' => ['get_entry']]);
    });

    it('misses after a tool class is edited, with no manual invalidation', function () {
        $cache = new DiscoveryCache($this->wrapped, devMode: true, version: '1.0.0');
        $cache->of($this->source)->set('discovery', ['tools' => ['get_entry']]);

        file_put_contents($this->source . '/tools/EntryTools.php', '<?php // entries, plus a new tool');

        expect($cache->of($this->source)->get('discovery', 'miss'))->toBe('miss');
    });

    it('misses after a tool class is added', function () {
        $cache = new DiscoveryCache($this->wrapped, devMode: true, version: '1.0.0');
        $cache->of($this->source)->set('discovery', ['tools' => ['get_entry']]);

        file_put_contents($this->source . '/tools/AssetTools.php', '<?php // assets');

        expect($cache->of($this->source)->get('discovery', 'miss'))->toBe('miss');
    });

    it('hits again when the code returns to a state it already scanned', function () {
        $cache = new DiscoveryCache($this->wrapped, devMode: true, version: '1.0.0');
        $original = (string) file_get_contents($this->source . '/tools/EntryTools.php');
        $mtime = filemtime($this->source . '/tools/EntryTools.php');

        $cache->of($this->source)->set('discovery', ['tools' => ['get_entry']]);

        file_put_contents($this->source . '/tools/EntryTools.php', '<?php // on the other branch');
        $cache->of($this->source)->set('discovery', ['tools' => ['other']]);

        file_put_contents($this->source . '/tools/EntryTools.php', $original);
        touch($this->source . '/tools/EntryTools.php', $mtime);

        expect($cache->of($this->source)->get('discovery', 'miss'))
            ->toBe(['tools' => ['get_entry']]);
    });

    it('bounds each generation with a TTL so superseded scans do not pile up', function () {
        $wrapped = new RecordingCache();

        (new DiscoveryCache($wrapped, devMode: true, version: '1.0.0'))
            ->of($this->source)
            ->set('discovery', ['tools' => []]);

        expect($wrapped->lastDuration())->toBe(604800);
    });
});

describe('DiscoveryCache outside devMode', function () {
    it('keeps a stable key across code changes, doing no filesystem work', function () {
        $cache = new DiscoveryCache($this->wrapped, devMode: false, version: '1.4.0');
        $cache->of($this->source)->set('discovery', ['tools' => ['get_entry']]);

        file_put_contents($this->source . '/tools/EntryTools.php', '<?php // edited in production');

        expect($cache->of($this->source)->get('discovery', 'miss'))
            ->toBe(['tools' => ['get_entry']]);
    });

    it('misses after a plugin upgrade', function () {
        (new DiscoveryCache($this->wrapped, devMode: false, version: '1.4.0'))
            ->of($this->source)
            ->set('discovery', ['tools' => ['get_entry']]);

        expect((new DiscoveryCache($this->wrapped, devMode: false, version: '1.5.0'))
            ->of($this->source)
            ->get('discovery', 'miss'))
            ->toBe('miss');
    });

    it('stores indefinitely, since the key only turns over on upgrade', function () {
        $wrapped = new RecordingCache();

        (new DiscoveryCache($wrapped, devMode: false, version: '1.4.0'))
            ->of($this->source)
            ->set('discovery', ['tools' => []]);

        // Yii resolves a null duration to its defaultDuration, and 0 is Yii's
        // "never expires".
        expect($wrapped->lastDuration())->toBe(0);
    });
});

describe('DiscoveryCache tagged invalidation', function () {
    it('still clears a revisioned entry through the shared tag, as reload_mcp does', function () {
        $cache = new DiscoveryCache($this->wrapped, devMode: true, version: '1.0.0');
        $cache->of($this->source)->set('discovery', ['tools' => ['get_entry']]);

        TagDependency::invalidate($this->wrapped, Psr16CacheAdapter::TAG);

        expect($cache->of($this->source)->get('discovery', 'miss'))->toBe('miss');
    });
});
