<?php

namespace Sunnysideup\AssetsOverview\Traits;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;

trait Cacher
{

    private static $loadedFromCache = true;

    private static $cacheCache = null;

    /**
     * return false if the cache has been set or a cache key was not found.
     * @return bool
     */
    public static function loadedFromCache() : bool
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
        if (self::$cacheCache == null) {
            self::$cacheCache = Injector::inst()->get(CacheInterface::class . '.assetsoverviewCache');
        }
        return self::$cacheCache;
    }

    /**
     * @param string  $cacheKey
     * @param mixed   $value
     */
    protected function setCacheValue(string $cacheKey, $value)
    {
        self::$loadedFromCache = false;
        $cache = self::getCache();

        $cache->set($cacheKey, serialize($value));
    }

    /**
     * @param string  $cacheKey
     *
     * @return mixed
     */
    protected function getCacheValue(string $cacheKey)
    {
        $cache = self::getCache();

        return unserialize($cache->get($cacheKey));
    }

    /**
     *
     * @param  string $cacheKey
     * @return bool
     */
    protected function hasCacheKey(string $cacheKey) : bool
    {
        $cache = self::getCache();

        return $cache->has($cacheKey);
    }
}
