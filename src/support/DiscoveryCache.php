<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use stimmt\craft\Mcp\psr\Cache;
use yii\caching\CacheInterface as YiiCacheInterface;

/**
 * Builds the cache the SDK's attribute discovery writes into, keyed on the
 * state of the code that discovery scanned.
 *
 * WHY: the cached DiscoveryState is a snapshot of what reflection found in the
 * scanned classes, yet nothing about the scan's own parameters changes when
 * that code does. Under a fixed key, editing a tool, adding a tool class or
 * switching branches left tools/list serving the previous scan indefinitely.
 * Reconnecting the client did not help and neither did restarting the
 * container, because the staleness lives in Craft's persistent cache rather
 * than in the process, so the only way out was clearing caches by hand.
 * Folding a revision into the key prefix means changed code simply misses and
 * rescans.
 *
 * The revision is picked for the cost it is worth paying:
 *
 * - devMode: a stat-based fingerprint of the plugin's own source. It costs
 *   well under a millisecond for a tree this size, which is nothing next to
 *   the discovery it guards, and it is the only thing that makes developing
 *   tools against this plugin bearable. The whole basePath is fingerprinted
 *   rather than only the scanned directories, because the enums and attributes
 *   beside them shape the generated schemas too.
 * - otherwise: the plugin version. Production keeps a stable key with no
 *   filesystem work per request, and the key still turns over on upgrade.
 *
 * Known bound: code that changes under a version that does not (a dev branch
 * deployed with devMode off) is not detected. The reload_mcp tool invalidates
 * by tag for exactly that case.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class DiscoveryCache {
    /**
     * How long one devMode generation is kept. Every distinct state of the
     * source gets its own key, so without an expiry each edit would leave its
     * predecessor behind in the shared cache forever, and a discovery state
     * weighs tens of kilobytes. A week is long enough that bouncing between
     * two branches still hits a warm cache in both directions.
     */
    private const int DEV_TTL = 604800;

    public function __construct(
        private YiiCacheInterface $cache,
        private bool $devMode,
        private string $version,
        private SourceFingerprint $fingerprint = new SourceFingerprint(),
    ) {
    }

    public function of(string $basePath): Cache {
        $revision = $this->devMode ? $this->fingerprint->of($basePath) : $this->version;

        return new Cache(
            cache: $this->cache,
            prefix: "mcp-discovery:{$revision}:",
            defaultTtl: $this->devMode ? self::DEV_TTL : null,
        );
    }
}
