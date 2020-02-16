<?php

namespace Sunnysideup\AssetsOverview\Traits;

use Psr\SimpleCache\CacheInterface;

trait Cacher
{

    private static $cacheCache = null;

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
    protected static function setCacheValue(string $cacheKey, $value)
    {
        $cache = self::getCache();

        $cache->set($cacheKey, serialize($value));
    }

    /**
     * @param string  $cacheKey
     *
     * @return mixed
     */
    protected static function getCacheValue(string $cacheKey)
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
