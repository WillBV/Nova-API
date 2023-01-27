<?php

namespace nova\utilities\cache;

interface CacheInterface
{
    /**
     * Checks whether cached key exists.
     *
     * @param string $key The key to search for.
     *
     * @return boolean
     */
    public function exists(string $key): bool;

    /**
     * Gets a cached value by it's key.
     *
     * @param string $key The key of the cached value to get.
     *
     * @return mixed
     */
    public function get(string $key);

    /**
     * Gets the cached values of multiple keys.
     *
     * @param array $keys An array of keys to get the cached values of.
     *
     * @return array
     */
    public function getMulti(array $keys): array;

    /**
     * Cache a value by a key, overwriting if it already exists.
     *
     * @param string  $key      The key to cache the value by.
     * @param mixed   $value    The value to be cached.
     * @param integer $duration The duration to cache the value in seconds.
     *
     * @return boolean
     */
    public function set(string $key, mixed $value, int $duration): bool;

    /**
     * Caches multiple values by keys, each overwriting if it already exists.
     *
     * @param array $items The items to be cached. Key=Key, Value=array[value, duration].
     *
     * @return array
     */
    public function setMulti(array $items): array;

    /**
     * Caches a value by a key, returning false if it already exists.
     *
     * @param string  $key      The key to cache the value by.
     * @param mixed   $value    The value to be cached.
     * @param integer $duration The duration to cache the value in seconds.
     *
     * @return boolean
     */
    public function add(string $key, mixed $value, int $duration): bool;

    /**
     * Caches multiple values by keys, each returning false if it already exists.
     *
     * @param array $items The items to be cached. Key=Key, Value=array[value, duration].
     *
     * @return array
     */
    public function addMulti(array $items): array;

    /**
     * Removes a cached value by it's key.
     *
     * @param string $key The key of the value to remove.
     *
     * @return boolean
     */
    public function delete(string $key): bool;

    /**
     * Remove multiple cached values by their keys.
     *
     * @param array $keys The keys of the values to be removed.
     *
     * @return array
     */
    public function deleteMulti(array $keys): array;

    /**
     * Clears all values from the cache.
     *
     * @return boolean
     */
    public function flush(): bool;
}
