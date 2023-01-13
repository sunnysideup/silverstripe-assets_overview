<?php

namespace Sunnysideup\AssetsOverview\Traits;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;

trait Cacher
{
    private static $loadedFromCache = true;

    private static $cacheCache;

    /**
     * return false if the cache has been set or a cache key was not found.
     */
    public static function loadedFromCache(): bool
    {
        return self::$loadedFromCache;
    }

    public static function flushCache()
    {
        $cache = self::getCache();
        $cache->clear();
    }

    /**
     * @return CacheInterface
     */
    protected static function getCache()
    {
        if (null === self::$cacheCache) {
            self::$cacheCache = Injector::inst()->get(CacheInterface::class . '.assetsoverviewCache');
        }

        return self::$cacheCache;
    }

    /**
     * @param mixed $value
     */
    protected function setCacheValue(string $cacheKey, $value)
    {
        self::$loadedFromCache = false;
        $cache = self::getCache();

        $cache->set($cacheKey, serialize($value));
    }

    /**
     * @return mixed
     */
    protected function getCacheValue(string $cacheKey)
    {
        $cache = self::getCache();

        return unserialize((string) $cache->get($cacheKey));
    }

    protected function hasCacheKey(string $cacheKey): bool
    {
        $cache = self::getCache();

        return $cache->has($cacheKey);
    }
}
