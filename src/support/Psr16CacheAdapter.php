<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;
use stdClass;
use yii\caching\CacheInterface as YiiCacheInterface;
use yii\caching\TagDependency;

/**
 * PSR-16 adapter over Craft's Yii cache component, used to let the MCP SDK's
 * CachedDiscoverer skip the Finder+reflection scan on every HTTP request.
 *
 * Every write is tagged with self::TAG via a TagDependency, so clear() can
 * invalidate just the discovery entries without flushing the shared cache
 * component other plugins and Craft itself use. Yii's get() returns false on
 * a miss, which collides with a legitimately cached falsy value, so values
 * are wrapped in an array envelope on the way in and unwrapped on the way out.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Psr16CacheAdapter implements SimpleCacheInterface {
    public const string TAG = 'mcp-discovery';

    public function __construct(
        private YiiCacheInterface $cache,
        private string $prefix = 'mcp-discovery:',
    ) {
    }

    public function get(string $key, mixed $default = null): mixed {
        $envelope = $this->cache->get($this->prefix . $key);
        if (!is_array($envelope) || !array_key_exists('v', $envelope)) {
            return $default;
        }

        return $envelope['v'];
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool {
        $normalizedTtl = $this->normalizeTtl($ttl);

        // PSR-16: Zero or negative TTL means immediate expiry (delete the key)
        if ($ttl !== null && $normalizedTtl <= 0) {
            return $this->delete($key);
        }

        return $this->cache->set(
            $this->prefix . $key,
            ['v' => $value],
            $normalizedTtl,
            new TagDependency(['tags' => self::TAG]),
        );
    }

    public function delete(string $key): bool {
        return $this->cache->delete($this->prefix . $key);
    }

    /**
     * Invalidates only entries tagged self::TAG; never flushes the wrapped
     * cache component, which is shared with the rest of the Craft install.
     */
    public function clear(): bool {
        TagDependency::invalidate($this->cache, self::TAG);

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool {
        $success = true;
        foreach ($values as $key => $value) {
            $success = $this->set((string) $key, $value, $ttl) && $success;
        }

        return $success;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool {
        $success = true;
        foreach ($keys as $key) {
            $success = $this->delete($key) && $success;
        }

        return $success;
    }

    public function has(string $key): bool {
        $miss = new stdClass();

        return $this->get($key, $miss) !== $miss;
    }

    private function normalizeTtl(null|int|DateInterval $ttl): ?int {
        if ($ttl instanceof DateInterval) {
            return (new DateTimeImmutable())->add($ttl)->getTimestamp() - time();
        }

        return $ttl;
    }
}
