<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

use Illuminate\Contracts\Cache\Store;
use RuntimeException;

/**
 * A cache store whose every operation fails, the way a Redis store does when
 * its connection is refused or times out. Counts the operations tried.
 */
class ThrowingCacheStore implements Store
{
    public static int $calls = 0;

    public static function register(): void
    {
        self::$calls = 0;

        // extend() rebinds the closure to the manager, where `self` would name the manager.
        app('cache')->extend('throwing', fn () => app('cache')->repository(new ThrowingCacheStore));
    }

    private function fail(): never
    {
        self::$calls++;

        throw new RuntimeException('Connection refused [tcp://192.0.2.1:6379]');
    }

    public function get($key)
    {
        $this->fail();
    }

    public function many(array $keys)
    {
        $this->fail();
    }

    public function put($key, $value, $seconds)
    {
        $this->fail();
    }

    public function putMany(array $values, $seconds)
    {
        $this->fail();
    }

    public function increment($key, $value = 1)
    {
        $this->fail();
    }

    public function decrement($key, $value = 1)
    {
        $this->fail();
    }

    public function forever($key, $value)
    {
        $this->fail();
    }

    public function forget($key)
    {
        $this->fail();
    }

    public function flush()
    {
        $this->fail();
    }

    public function getPrefix()
    {
        return '';
    }

    public function add($key, $value, $seconds)
    {
        $this->fail();
    }
}
