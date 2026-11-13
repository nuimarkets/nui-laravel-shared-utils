<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use Throwable;

/**
 * Asks the token issuer whether an access token is still active, caching the
 * answer briefly.
 *
 * Call it after the token's signature and expiry have been verified, with the
 * token's `jti` and `exp` claims. The issuer is asked only on a cache miss:
 *
 * - 200 `{"active": true}` is cached as `active` until `t0 + active_ttl`,
 *   where t0 is when the check started, and only if no entry exists, so a
 *   slow positive answer never replaces a newer `revoked`.
 * - 200 `{"active": false}` is cached as `revoked`, overwriting, until
 *   `revoked_grace` seconds past the token's expiry.
 * - Anything else is `unavailable`: nothing is cached, and the breaker key
 *   makes cache misses skip the call for `breaker_seconds`.
 *
 * A cache failure skips the cache and the breaker and still makes the call.
 * The whole check, this service's machine token included, runs inside
 * `timeout` seconds. Never throws.
 */
class TokenStatusClient
{
    /** The status body is read up to this many bytes; a longer one is not an answer. */
    public const MAX_BODY_BYTES = 1024;

    public const KEY_PREFIX = 'token_status:';

    public const BREAKER_KEY = 'token_status:breaker';

    /** Less budget than this cannot carry a call, and a sub-millisecond Guzzle timeout means none. */
    private const MIN_CALL_SECONDS = 0.01;

    public function __construct(private readonly MachineTokenService $machineTokens) {}

    public function status(string $jti, int $exp): TokenStatus
    {
        return $this->check($jti, $exp)->status;
    }

    /**
     * The status plus what the guard logs about it: cache hit or miss, the
     * reason for `unavailable`, and latency.
     */
    public function check(string $jti, int $exp): TokenStatusCheck
    {
        $started = hrtime(true);

        try {
            [$status, $cacheHit, $reason, $cacheError] = $this->resolve($jti, $exp);
        } catch (Throwable) {
            [$status, $cacheHit, $reason, $cacheError] = [TokenStatus::Unavailable, false, 'unexpected', false];
        }

        return new TokenStatusCheck($status, $cacheHit, $reason, (hrtime(true) - $started) / 1e6, $cacheError);
    }

    /**
     * @return array{0: TokenStatus, 1: bool, 2: string|null, 3: bool}
     */
    private function resolve(string $jti, int $exp): array
    {
        $url = (string) config('token_revocation.url', '');

        if ($url === '') {
            return [TokenStatus::Unavailable, false, 'not_configured', false];
        }

        if ($jti === '') {
            return [TokenStatus::Unavailable, false, 'missing_jti', false];
        }

        $t0 = $this->now();
        $deadline = $t0 + (float) config('token_revocation.timeout', 2);
        $key = self::KEY_PREFIX.$jti;

        $store = $this->store();
        $values = [];

        if ($store !== null) {
            try {
                $values = $store->many([$key, self::BREAKER_KEY]);
            } catch (Throwable) {
                $store = null;
            }
        }

        $cacheError = $store === null;

        if ($store !== null) {
            $cached = $this->cachedStatus($values[$key] ?? null, $t0);

            if ($cached !== null) {
                return [$cached, true, null, false];
            }

            if (($values[self::BREAKER_KEY] ?? null) !== null) {
                return [TokenStatus::Unavailable, false, 'breaker_open', false];
            }
        }

        $answer = $this->ask($url, $jti, $deadline);

        if (! is_bool($answer)) {
            if ($store !== null) {
                $this->quietly(fn () => $store->put(
                    self::BREAKER_KEY,
                    1,
                    (int) config('token_revocation.breaker_seconds', 10)
                ));
            }

            return [TokenStatus::Unavailable, false, $answer, $cacheError];
        }

        if ($store !== null) {
            $this->quietly(fn () => $this->remember($store, $key, $answer, $exp, $t0));
        }

        return [$answer ? TokenStatus::Active : TokenStatus::Revoked, false, null, $cacheError];
    }

    /** A cached `revoked`, or a cached `active` whose active_until is still ahead. Anything else is a miss. */
    private function cachedStatus(mixed $entry, float $now): ?TokenStatus
    {
        if (! is_array($entry)) {
            return null;
        }

        $status = $entry['status'] ?? null;

        if ($status === TokenStatus::Revoked->value) {
            return TokenStatus::Revoked;
        }

        $activeUntil = $entry['active_until'] ?? null;

        if ($status === TokenStatus::Active->value && is_numeric($activeUntil) && (float) $activeUntil > $now) {
            return TokenStatus::Active;
        }

        return null;
    }

    /**
     * Calls the status route, retrying once with a new machine token if the
     * route refuses this service's token (401 or 403).
     *
     * @return bool|string The `active` answer, or why there is none.
     */
    private function ask(string $url, string $jti, float $deadline): bool|string
    {
        for ($attempt = 1; ; $attempt++) {
            $remaining = $deadline - $this->now();

            if ($remaining < self::MIN_CALL_SECONDS) {
                return 'deadline';
            }

            try {
                $machineToken = $this->machineTokens->getToken($remaining);
            } catch (Throwable) {
                return 'machine_token';
            }

            $remaining = $deadline - $this->now();

            if ($remaining < self::MIN_CALL_SECONDS) {
                return 'deadline';
            }

            $sink = new BoundedBodyStream(Utils::streamFor(''), self::MAX_BODY_BYTES);

            try {
                $response = $this->post($url, $jti, $machineToken, $remaining, $sink);
                $code = $response->status();
            } catch (Throwable) {
                return $sink->overflowed() ? 'oversized' : 'connection';
            }

            if (($code === 401 || $code === 403) && $attempt === 1) {
                $this->quietly(fn () => $this->machineTokens->invalidate());

                continue;
            }

            if ($code !== 200) {
                return 'http_'.$code;
            }

            return $this->readActive($response) ?? 'malformed';
        }
    }

    /**
     * The sink takes the decoded body as it arrives and aborts the transfer past
     * MAX_BODY_BYTES, so an oversized or inflating response is never buffered.
     * Http::fake() responses bypass the sink; readActive() caps those.
     */
    private function post(string $url, string $jti, string $machineToken, float $remaining, BoundedBodyStream $sink): Response
    {
        return Http::withToken($machineToken)
            ->acceptJson()
            ->withoutRedirecting()
            ->withOptions([
                'connect_timeout' => min((float) config('token_revocation.connect_timeout', 1), $remaining),
                'timeout' => $remaining,
                'sink' => $sink,
            ])
            ->post($url, ['jti' => $jti]);
    }

    /** Only a JSON object whose `active` is a boolean is an answer. */
    private function readActive(Response $response): ?bool
    {
        $body = $response->toPsrResponse()->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $raw = '';

        while (strlen($raw) <= self::MAX_BODY_BYTES && ! $body->eof()) {
            $chunk = $body->read(self::MAX_BODY_BYTES + 1 - strlen($raw));

            if ($chunk === '') {
                break;
            }

            $raw .= $chunk;
        }

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return null;
        }

        $data = json_decode($raw, true, 8);

        if (! is_array($data) || ! array_key_exists('active', $data) || ! is_bool($data['active'])) {
            return null;
        }

        return $data['active'];
    }

    private function remember(CacheRepository $store, string $key, bool $active, int $exp, float $t0): void
    {
        $now = $this->now();

        if ($active) {
            $secondsLeft = $exp - $now;

            if ($secondsLeft <= 0) {
                return;
            }

            $activeTtl = (int) config('token_revocation.active_ttl', 30);
            $ttl = (int) min($activeTtl, floor($secondsLeft));

            if ($ttl <= 0) {
                return;
            }

            $store->add($key, [
                'status' => TokenStatus::Active->value,
                'active_until' => $t0 + $activeTtl,
            ], $ttl);

            return;
        }

        $grace = (int) config('token_revocation.revoked_grace', 60);

        $store->put($key, ['status' => TokenStatus::Revoked->value], (int) ceil(max($grace, $exp + $grace - $now)));
    }

    private function store(): ?CacheRepository
    {
        try {
            return Cache::store(config('token_revocation.store') ?: null);
        } catch (Throwable) {
            return null;
        }
    }

    /** Wall-clock seconds, from Carbon so tests can travel in time with the cache. */
    private function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }

    private function quietly(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // A failed cache write or token invalidation leaves the answer intact.
        }
    }
}
