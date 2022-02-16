<?php


namespace MMHK\common;


trait withCache
{
    protected $cacheDir = null;

    public function setCache($key, $value) {
        $cache = new CacheHelper($this->cacheDir);
        return $cache->put($key, $value);
    }

    public function getCache($key, $default = null) {
        $cache = new CacheHelper($this->cacheDir);
        return $cache->get($key, $default);
    }

    public function flushCache() {
        $cache = new CacheHelper($this->cacheDir);
        $cache->flush();
    }
}