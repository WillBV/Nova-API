<?php

namespace nova\utilities\cache;

use nova\Nova;
use nova\utilities\Utilities;

class Cache
{
    protected $driver;
    protected $duration = 0;

    /**
     * Construct the cache class to be used.
     *
     * @param string $cache The cache class that should be used.
     */
    public function __construct(string $cache) {
        try {
            $this->duration = (int)($_ENV["CACHE_DURATION"] ?? 86400);
            switch ($cache) {
                case "apcu":
                    $this->driver = new APCuCache($this->duration);
                    break;
            }
        } catch (\Exception $e) {
            $exception = Utilities::parseException($e);
            Nova::log($exception["message"], "error");
        }
    }

    /**
     * Checks whether cached key exists.
     *
     * @param string $key The key to search for.
     *
     * @return boolean
     */
    public function exists(string $key): bool {
        $return = FALSE;
        if ($this->driver) {
            $return = $this->driver->exists($key);
        }
        return $return;
    }

    /**
     * Gets a cached value by it's key.
     *
     * @param string $key The key of the cached value to get.
     *
     * @return mixed
     */
    public function get(string $key) {
        $return = "";
        if ($this->driver) {
            $return = $this->driver->get($key);
        }
        return $return;
    }

    /**
     * Gets the cached values of multiple keys.
     *
     * @param array $keys An array of keys to get the cached values of.
     *
     * @return array
     */
    public function getMulti(array $keys): array {
        foreach ($keys as $key) {
            $return[$key] = FALSE;
        }
        if ($this->driver) {
            $return = $this->driver->getMulti($keys);
        }
        return $return;
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
    public function set(string $key, mixed $value, int $duration = NULL): bool {
        $return   = FALSE;
        $duration = $duration !== NULL ? (int)$duration : $this->duration;
        if ($this->driver) {
            $return = $this->driver->set($key, $value, $duration);
        }
        return $return;
    }

    /**
     * Caches multiple values by keys, each overwriting if it already exists.
     *
     * @param array $items The items to be cached. Key=Key, Value=array[value, duration].
     *
     * @return array
     */
    public function setMulti(array $items): array {
        foreach (array_keys($items) as $key) {
            $return[$key] = FALSE;
        }
        if ($this->driver) {
            $return = $this->driver->setMulti($items);
        }
        return $return;
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
    public function add(string $key, mixed $value, int $duration = NULL): bool {
        $return   = FALSE;
        $duration = $duration !== NULL ? (int)$duration : $this->duration;
        if ($this->driver) {
            $return = $this->driver->add($key, $value, $duration);
        }
        return $return;
    }

    /**
     * Caches multiple values by keys, each returning false if it already exists.
     *
     * @param array $items The items to be cached. Key=Key, Value=array[value, duration].
     *
     * @return array
     */
    public function addMulti(array $items): array {
        foreach (array_keys($items) as $key) {
            $return[$key] = FALSE;
        }
        if ($this->driver) {
            $return = $this->driver->addMulti($items);
        }
        return $return;
    }

    /**
     * Removes a cached value by it's key.
     *
     * @param string $key The key of the value to remove.
     *
     * @return boolean
     */
    public function delete(string $key): bool {
        $return = FALSE;
        if ($this->driver) {
            $return = $this->driver->delete($key);
        }
        return $return;
    }

    /**
     * Remove multiple cached values by their keys.
     *
     * @param array $keys The keys of the values to be removed.
     *
     * @return array
     */
    public function deleteMulti(array $keys): array {
        $return = [];
        if ($this->driver) {
            $return = $this->driver->deleteMulti($keys);
        }
        return $return;
    }

    /**
     * Clears all values from the cache.
     *
     * @return boolean
     */
    public function flush(): bool {
        $return = FALSE;
        if ($this->driver) {
            $return = $this->driver->flush();
        }
        return $return;
    }
}
