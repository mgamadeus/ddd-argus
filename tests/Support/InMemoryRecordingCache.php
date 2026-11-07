<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Infrastructure\Cache\Apc;
use LogicException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

/**
 * A cache group in memory that records the TTL of every write. {@see installForArgusCacheGroups()} puts one instance
 * under the APC and the Redis Sentinel group, the two groups ArgusCache writes to.
 */
class InMemoryRecordingCache extends Apc
{
    /** @var array<string, mixed> */
    public array $valuesByKey = [];

    /** @var array<string, int|null> The TTL each key was last written with */
    public array $ttlsByKey = [];

    /** @return InMemoryRecordingCache[] the installed caches by group */
    public static function installForArgusCacheGroups(): array
    {
        $installedCaches = [
            self::CACHE_GROUP_APC => new self(['defaultTTL' => 60]),
            self::CACHE_GROUP_REDIS_SENTINEL => new self(['defaultTTL' => 60]),
        ];
        static::$instances = $installedCaches + (static::$instances ?? []);
        return $installedCaches;
    }

    public static function uninstall(): void
    {
        unset(static::$instances[self::CACHE_GROUP_APC], static::$instances[self::CACHE_GROUP_REDIS_SENTINEL]);
    }

    public function get(string ...$keys): mixed
    {
        if (count($keys) > 1) {
            $values = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $this->valuesByKey)) {
                    $values[$key] = $this->valuesByKey[$key];
                }
            }
            return $values;
        }
        return $this->valuesByKey[$keys[0]] ?? false;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, bool $deferred = false): void
    {
        $this->valuesByKey[$key] = $value;
        $this->ttlsByKey[$key] = $ttl;
    }

    public function delete(string $key): void
    {
        unset($this->valuesByKey[$key], $this->ttlsByKey[$key]);
    }

    public function getCacheAdapter(): ApcuAdapter
    {
        throw new LogicException('The in-memory test cache has no adapter');
    }
}
