<?php

namespace nova\utilities\cache;

use nova\Nova;

class APCuCache implements CacheInterface
{
    protected $duration = 0;

    /**
     * Constructs the APCu Cache by checking the extension is installed & working.
     *
     * @param integer $duration The default duration to cache values.
     */
    public function __construct(int $duration) {
        $apcuAvailabe = function_exists("apcu_enabled") && apcu_enabled();
        if (!$apcuAvailabe) {
            throw new \Exception(
                "APCu caching is unavailable, please make sure the php-apcu extension is installed.",
                1
            );
        }
        $this->duration = $duration;
    }

    /**
     * Checks whether cached key exists.
     *
     * @param string $key The key to search for.
     *
     * @return boolean
     */
    public function exists(string $key): bool {
        return apcu_exists($key);
    }

    /**
     * Gets a cached value by it's key.
     *
     * @param string $key The key of the cached value to get.
     *
     * @return mixed
     */
    public function get(string $key) {
        return apcu_fetch($key);
    }

    /**
     * Gets the cached values of multiple keys.
     *
     * @param array $keys An array of keys to get the cached values of.
     *
     * @return array
     */
    public function getMulti(array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * Cache a value by a key, overwriting if it already exists.
     *
     * @param string  $key      The key to cache the value by.
     * @param mixed   $value    The value to be cached.
     * @param integer $duration The duration to cache the value in seconds.
     *
     * @return boolean
     */
    public function set(string $key, mixed $value, int $duration): bool {
        return apcu_store($key, $value, $duration);
    }

    /**
     * Caches multiple values by keys, each overwriting if it already exists.
     *
     * @param array $items The items to be cached. Key=Key, Value=array[value, duration].
     *
     * @return array
     */
    public function setMulti(array $items): array {
        $result = [];
        foreach ($items as $key => $data) {
            $value    = $data[0];
            $duration = $data[1] ?? $this->duration;
            $success  = $this->set($key, $value, $duration);
            if (!$success) {
                $result[$key] = FALSE;
            }
        }
        return $result;
    }

    /**
     * Caches a value by a key, returning false if it already exists.
     *
     * @param string  $key      The key to cache the value by.
     * @param mixed   $value    The value to be cached.
     * @param integer $duration The duration to cache the value in seconds.
     *
     * @return boolean
     */
    public function add(string $key, mixed $value, int $duration): bool {
        return apcu_add($key, $value, $duration);
    }

    /**
     * Caches multiple values by keys, each returning false if it already exists.
     *
     * @param array $items The items to be cached. Key=Key, Value=array[value, duration].
     *
     * @return array
     */
    public function addMulti(array $items): array {
        $result = [];
        foreach ($items as $key => $data) {
            $value    = $data[0];
            $duration = $data[1] ?? $this->duration;
            $success  = $this->add($key, $value, $duration);
            if (!$success) {
                $result[$key] = FALSE;
            }
        }
        return $result;
    }

    /**
     * Removes a cached value by it's key.
     *
     * @param string $key The key of the value to remove.
     *
     * @return boolean
     */
    public function delete(string $key): bool {
        return apcu_delete($key);
    }

    /**
     * Remove multiple cached values by their keys.
     *
     * @param array $keys The keys of the values to be removed.
     *
     * @return array
     */
    public function deleteMulti(array $keys): array {
        $result = [];
        foreach ($keys as $key) {
            $success = $this->delete($key);
            if (!$success) {
                $result[$key] = FALSE;
            }
        }
        return $result;
    }

    /**
     * Clears all values from the cache.
     *
     * @return boolean
     */
    public function flush(): bool {
        return apcu_clear_cache();
    }
}
