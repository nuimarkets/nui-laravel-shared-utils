<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Utils;

class FakeRedisConnection
{
    public array $sets = [];

    public array $deleted = [];

    private array $store = [];

    private array $expiresAt = [];

    public function set(string $key, string $value, ...$options): bool
    {
        $this->purgeExpired($key);

        if (in_array('NX', $options, true) && array_key_exists($key, $this->store)) {
            return false;
        }

        $ttl = $this->extractTtl($options);
        $this->store[$key] = $value;
        $this->expiresAt[$key] = $ttl === null ? null : now()->getTimestamp() + $ttl;
        $this->sets[] = [
            'key' => $key,
            'value' => $value,
            'options' => $options,
            'ttl' => $ttl,
        ];

        return true;
    }

    public function get(string $key): ?string
    {
        $this->purgeExpired($key);

        return $this->store[$key] ?? null;
    }

    public function del(string $key): int
    {
        $this->purgeExpired($key);
        $deleted = array_key_exists($key, $this->store) ? 1 : 0;

        unset($this->store[$key], $this->expiresAt[$key]);
        $this->deleted[] = $key;

        return $deleted;
    }

    public function keys(): array
    {
        foreach (array_keys($this->store) as $key) {
            $this->purgeExpired($key);
        }

        return array_keys($this->store);
    }

    public function payload(string $key): ?array
    {
        $value = $this->get($key);

        return $value === null ? null : json_decode($value, true);
    }

    public function ttlFor(string $key): ?int
    {
        foreach (array_reverse($this->sets) as $set) {
            if ($set['key'] === $key) {
                return $set['ttl'];
            }
        }

        return null;
    }

    private function extractTtl(array $options): ?int
    {
        foreach ($options as $index => $option) {
            if ($option === 'EX' && isset($options[$index + 1])) {
                return (int) $options[$index + 1];
            }
        }

        return null;
    }

    private function purgeExpired(string $key): void
    {
        if (($this->expiresAt[$key] ?? null) !== null && $this->expiresAt[$key] <= now()->getTimestamp()) {
            unset($this->store[$key], $this->expiresAt[$key]);
        }
    }
}
