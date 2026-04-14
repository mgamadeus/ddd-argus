<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

use DDD\Infrastructure\Cache\Cache as InfrastructureCache;

/**
 * getMulti is essential here, it loads multiple cache elements with a single redis query and
 * by thid reduces roundtrip times significantly
 */
class ArgusCache
{
    public const CACHELEVEL_MEMORY_AND_DB = 3;
    public const CACHELEVEL_MEMORY = 1; //api_objects may be cached in both
    public const CACHELEVEL_DB = 2; //calls are cached only in memory
    public const CACHELEVEL_NONE = 0;
    public const CACHE_TTL_ONE_DAY = 86400;
    public const CACHE_TTL_ONE_HOUR = 3600;
    public const CACHE_TTL_THIRTY_MINUTES = 1800;
    public const CACHE_TTL_TEN_MINUTES = 600;
    public const CACHE_TTL_ONE_WEEK = 604800;
    public const CACHE_TTL_ONE_MONTH = 2292000;
    protected static $defaultCachePrefix = 'ArgusCache_';

    /**
     * On achce Systems as Redis we can load multiple keys at once and by by
     * this a significant reduction in roundtrip times can be achieved
     * @param $keys
     * @return array
     */
    public static function getMulti($keys): ?array
    {
        $cacheRedis = InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS_SENTINEL);
        $argusCacheKeys = [];
        $argusCacheKeyToCacheKeyAllocation = [];

        // We need to translate the cache keys to a format that is save for redis, as some of our keys have issues
        // At the end we translate them back to the original cache keys
        foreach ($keys as $cacheKey) {
            $argusCacheKey = self::getCacheKey($cacheKey);
            $argusCacheKeyToCacheKeyAllocation[$argusCacheKey] = $cacheKey;
            $argusCacheKeys[] = $argusCacheKey;
        }
        $cachedRedisMulti = $cacheRedis->get(...$argusCacheKeys);
        if ($cachedRedisMulti && !is_array($cachedRedisMulti)) {
            // only one result returned
            $cachedRedisMulti = [$argusCacheKeys[0] => $cachedRedisMulti];
        }
        $cachedRedisMultiFinal = [];

        if (!$cachedRedisMulti) {
            return null;
        }
        if (!is_array($cachedRedisMulti)) {
            $cachedRedisMulti = [$cachedRedisMulti];
        }
        foreach ($cachedRedisMulti as $argusCacheKey => $cachedResult) {
            if (!$cachedResult) {
                continue;
            }
            $cacheKey = $argusCacheKeyToCacheKeyAllocation[$argusCacheKey];
            $cachedRedisMultiFinal[$cacheKey] = $cachedResult;
            self::set($cacheKey, $cachedResult, null, self::CACHELEVEL_MEMORY);
        }

        return $cachedRedisMultiFinal;
    }

    public static function get($cacheKey, $cacheLevel = self::CACHELEVEL_MEMORY_AND_DB): ?ArgusCacheItem
    {
        $cacheItem = new ArgusCacheItem();

        if ($cacheLevel != self::CACHELEVEL_NONE) {
            $cache = null;
            if ($cacheLevel == self::CACHELEVEL_MEMORY || $cacheLevel == self::CACHELEVEL_MEMORY_AND_DB) {
                $cache = InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_APC);
                $cached = $cache->get(self::getCacheKey($cacheKey));

                if ($cached !== false) {
                    $cacheItem->loaded = true;
                    $cacheItem->validUntil = time() + 3600;
                    $cacheItem->data = $cached;
                    $cacheItem->cacheSource = self::CACHELEVEL_MEMORY;
                    return $cacheItem;
                }
            }

            if ($cacheLevel == self::CACHELEVEL_DB || $cacheLevel == self::CACHELEVEL_MEMORY_AND_DB) {
                $cachedRedis = InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS_SENTINEL)->get(
                    self::getCacheKey($cacheKey)
                );

                if ($cachedRedis) {
                    $cacheItem->cacheSource = self::CACHELEVEL_DB;
                    $cacheItem->data = $cachedRedis;
                    if (!$cache) {
                        $cache = InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS_SENTINEL);
                    }
                    $cache->set(self::getCacheKey($cacheKey), $cachedRedis);

                    return $cacheItem;
                }
                unset($cachedRedis);
            }
        }
        return null;
    }

    public static function set($cacheKey, string $data, $ttl = null, $cacheLevel = self::CACHELEVEL_MEMORY_AND_DB): void
    {
        if ($cacheLevel == self::CACHELEVEL_NONE) {
            return;
        }

        if ($cacheLevel == self::CACHELEVEL_MEMORY || $cacheLevel == self::CACHELEVEL_MEMORY_AND_DB) {
            $apcTTL = $ttl;

            if ($apcTTL > self::CACHE_TTL_ONE_HOUR) {
                $apcTTL = self::CACHE_TTL_ONE_HOUR;
            }
            InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_APC)->set(
                self::getCacheKey($cacheKey),
                $data
            );
        }

        if ($cacheLevel == self::CACHELEVEL_DB || $cacheLevel == self::CACHELEVEL_MEMORY_AND_DB) {
            InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS_SENTINEL)->set(
                self::getCacheKey($cacheKey),
                $data
            );
            return;
        }
        return;
    }

    public static function delete($cacheKey, $cacheLevel = self::CACHELEVEL_MEMORY_AND_DB): void
    {
        if ($cacheLevel == self::CACHELEVEL_NONE) {
            return;
        }

        if ($cacheLevel == self::CACHELEVEL_MEMORY || $cacheLevel == self::CACHELEVEL_MEMORY_AND_DB) {
            InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_APC)->delete(self::getCacheKey($cacheKey));
        }

        if ($cacheLevel == self::CACHELEVEL_DB || $cacheLevel == self::CACHELEVEL_MEMORY_AND_DB) {
            InfrastructureCache::instance(InfrastructureCache::CACHE_GROUP_REDIS_SENTINEL)->delete(
                self::getCacheKey($cacheKey)
            );
        }
    }

    /**
     * Returns cache key combined with prefix and APP_ROOT_DIR in order to avoid workspaces influencing each other
     * @param string $cacheKey
     * @return string
     */
    public static function getCacheKey(string $cacheKey): string
    {
        return self::$defaultCachePrefix . '_' . md5(APP_ROOT_DIR . '_' . $cacheKey);
    }
}
