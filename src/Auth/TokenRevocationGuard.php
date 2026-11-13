<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use Throwable;

/**
 * Decides whether a validator should refuse a token whose signature and expiry
 * it has already verified, from TokenStatusClient's answer, the configured mode
 * and the configured failure setting, and logs the decision.
 *
 * - mode `off`: returns false with no cache read and no call.
 * - mode `shadow`: checks and logs `would reject`, returns false.
 * - mode `enforce`: returns true for `revoked`, and for `unavailable` when
 *   fail is `closed`. Logs `rejected`.
 *
 * Refusing the request is the caller's job, through its existing
 * invalid-token path. Never throws.
 */
class TokenRevocationGuard
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_ENFORCE = 'enforce';

    public const FAIL_OPEN = 'open';

    public const FAIL_CLOSED = 'closed';

    /** Seconds between repeats of the startup line from one process. */
    public const STARTUP_INTERVAL = 3600;

    /** Seconds between repeats of an `unavailable`, breaker-open or setting warning from one process. */
    public const WARNING_INTERVAL = 60;

    private const FEATURE = 'token_revocation';

    /** Claims copied into `would reject` and `rejected` lines. */
    private const LOGGED_CLAIMS = ['jti', 'sub', 'user_id', 'aud', 'grant_type'];

    /** @var array<string, int> When this instance last logged each rate-limited line. */
    private array $lastLogged = [];

    public function __construct(private readonly TokenStatusClient $client) {}

    /**
     * @param  string  $jti  The token's `jti` claim.
     * @param  int  $exp  The token's `exp` claim.
     * @param  array<string, mixed>  $claims  The token's claims, for the log lines.
     */
    public function shouldReject(string $jti, int $exp, array $claims): bool
    {
        [$mode, $modeValid] = $this->setting('mode', [self::MODE_OFF, self::MODE_SHADOW, self::MODE_ENFORCE], self::MODE_OFF);

        if ($mode === self::MODE_OFF) {
            if (! $modeValid) {
                $this->warnInvalidSettings();
            }

            return false;
        }

        [$fail, $failValid] = $this->setting('fail', [self::FAIL_OPEN, self::FAIL_CLOSED], self::FAIL_OPEN);

        if (! $failValid) {
            $this->warnInvalidSettings();
        }

        try {
            $check = $this->client->check($jti, $exp);
        } catch (Throwable) {
            $check = new TokenStatusCheck(TokenStatus::Unavailable, false, 'unexpected', 0.0);
        }

        // Not on a hit: the window check is a cache write, and a hit must cost
        // no more than the client's one read.
        if (! $check->cacheHit) {
            $this->announce($mode, $fail, $check->cacheError);
        }

        $reject = match ($check->status) {
            TokenStatus::Active => false,
            TokenStatus::Revoked => true,
            TokenStatus::Unavailable => $fail === self::FAIL_CLOSED,
        };

        $this->report($check, $reject, $mode, $fail, $jti, $claims);

        return $reject && $mode === self::MODE_ENFORCE;
    }

    private function report(TokenStatusCheck $check, bool $reject, string $mode, string $fail, string $jti, array $claims): void
    {
        $context = [
            LogFields::FEATURE => self::FEATURE,
            LogFields::RESULT => $check->status->value,
            'reason' => $check->reason,
            'mode' => $mode,
            'fail' => $fail,
        ];

        if ($reject) {
            $enforcing = $mode === self::MODE_ENFORCE;

            $this->log('warning', $enforcing ? 'Token revocation: rejected' : 'Token revocation: would reject', [
                ...$context,
                LogFields::ACTION => $enforcing ? 'rejected' : 'would_reject',
                ...$this->claimFields($jti, $claims),
                'validator' => config('app.name'),
            ]);
        }

        if ($check->status === TokenStatus::Unavailable) {
            $breakerOpen = $check->reason === 'breaker_open';

            if ($this->firstInWindow($breakerOpen ? 'breaker_open' : 'unavailable', self::WARNING_INTERVAL, $check->cacheError)) {
                $this->log('warning', $breakerOpen
                    ? 'Token revocation: breaker open, status not checked'
                    : 'Token revocation: status unavailable', [
                        ...$context,
                        LogFields::ACTION => $breakerOpen ? 'breaker_open' : 'unavailable',
                        'admitted' => ! ($reject && $mode === self::MODE_ENFORCE),
                    ]);
            }
        }

        if ($this->sampled()) {
            $this->log('info', 'Token revocation: check', [
                ...$context,
                LogFields::ACTION => 'check',
                LogFields::CACHE_HIT => $check->cacheHit,
                'cache_error' => $check->cacheError,
                LogFields::DURATION_MS => round($check->latencyMs, 3),
            ]);
        }
    }

    /**
     * The startup line, once per process per STARTUP_INTERVAL, logged on the
     * first check that misses the cache. Keyed by the settings too, so a
     * process that reuses a host and pid after a settings change logs again.
     */
    private function announce(string $mode, string $fail, bool $storeDown): void
    {
        if (! $this->firstInWindow("startup:{$mode}:{$fail}", self::STARTUP_INTERVAL, $storeDown)) {
            return;
        }

        $store = config('token_revocation.store');
        $url = (string) config('token_revocation.url', '');

        $this->log('info', 'Token revocation: check enabled', [
            LogFields::FEATURE => self::FEATURE,
            LogFields::ACTION => 'startup',
            'mode' => $mode,
            'fail' => $fail,
            'url_host' => $url === '' ? null : parse_url($url, PHP_URL_HOST),
            'cache_store' => $store,
            'cache_store_defined' => $store ? config("cache.stores.{$store}") !== null : true,
            'sample_rate' => (float) config('token_revocation.sample_rate', 0.01),
        ]);
    }

    private function warnInvalidSettings(): void
    {
        if (! $this->firstInWindow('invalid_setting', self::WARNING_INTERVAL)) {
            return;
        }

        $this->log('warning', 'Token revocation: unrecognised setting, using the default', [
            LogFields::FEATURE => self::FEATURE,
            LogFields::ACTION => 'invalid_setting',
            'mode_setting' => config('token_revocation.mode'),
            'fail_setting' => config('token_revocation.fail'),
        ]);
    }

    /**
     * @param  list<string>  $allowed
     * @return array{0: string, 1: bool} The value to use, and whether the configured one was recognised.
     */
    private function setting(string $name, array $allowed, string $default): array
    {
        $raw = config("token_revocation.{$name}");
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        if ($raw === null || $value === '') {
            return [$default, true];
        }

        return in_array($value, $allowed, true) ? [$value, true] : [$default, false];
    }

    /** @return array<string, mixed> */
    private function claimFields(string $jti, array $claims): array
    {
        $fields = [];

        foreach (self::LOGGED_CLAIMS as $claim) {
            $value = $claims[$claim] ?? null;
            $fields[$claim] = is_scalar($value) || is_array($value) ? $value : null;
        }

        $fields['jti'] = $jti;

        return $fields;
    }

    private function sampled(): bool
    {
        $rate = (float) config('token_revocation.sample_rate', 0.01);

        if ($rate <= 0) {
            return false;
        }

        return $rate >= 1 || random_int(1, 1_000_000) <= (int) round($rate * 1_000_000);
    }

    /**
     * True the first time this process asks for $name in each window. Under a
     * runtime that resets state per request (PHP-FPM, FrankenPHP without
     * workers) the in-memory note lasts one request, so the window is held in
     * the token status store under a key naming this host and process. If the
     * store fails, or the client has just found it down ($storeDown), the line
     * is logged at most once per request.
     */
    private function firstInWindow(string $name, int $seconds, bool $storeDown = false): bool
    {
        $now = Carbon::now()->getTimestamp();
        $last = $this->lastLogged[$name] ?? null;

        if ($last !== null && $now - $last < $seconds) {
            return false;
        }

        $this->lastLogged[$name] = $now;

        // Another attempt on a store the client could not read would cost
        // another connection timeout on the request.
        if ($storeDown) {
            return true;
        }

        try {
            return (bool) Cache::store(config('token_revocation.store') ?: null)->add(
                'token_revocation:log:'.$name.':'.gethostname().':'.getmypid(),
                1,
                $seconds
            );
        } catch (Throwable) {
            return true;
        }
    }

    private function log(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // Logging must not change whether the token is admitted.
        }
    }
}
