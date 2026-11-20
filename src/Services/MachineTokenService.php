<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Logging\ErrorLogger;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use NuiMarkets\LaravelSharedUtils\Support\CurlTransport;
use Throwable;

use function config;

class MachineTokenService implements MachineTokenServiceInterface
{
    /**
     * A budget shorter than this is treated as spent. Guzzle turns a timeout
     * under a millisecond into curl's 0, which means no timeout at all.
     */
    private const MIN_TIMEOUT_SECONDS = 0.01;

    /**
     * Refused tokens remembered per process. Bounds a long-lived worker whose
     * status route keeps refusing; a token older than this coming back costs
     * one more refusal and retry.
     */
    private const MAX_REFUSED = 16;

    /** Cache token for any subsequent calls to get within this request. */
    private string $tokenString = '';

    private ?array $oldToken = null;

    /**
     * Set by invalidate(): fingerprints of the tokens callers said were
     * refused, oldest first, so the credentials themselves are not kept. A
     * cached entry holding one is a miss, so the next fetch replaces it, while
     * a replacement another process has cached is used as it is. Every refused
     * token is remembered, not only the last: another process's failed refresh
     * can write an older one back.
     *
     * @var array<string, true>
     */
    private array $refused = [];

    /**
     * @param  float|null  $timeout  Seconds the caller can still spend. When given,
     *                               a fetch from the auth endpoint gets what is left
     *                               of it after the cache read, and follows no
     *                               redirects; without a cURL transport no fetch is
     *                               made. Null keeps the HTTP client's defaults.
     */
    public function getToken(?float $timeout = null): string
    {
        if ($this->tokenString === '') {
            $deadline = $timeout === null ? null : $this->now() + $timeout;

            /** Try redis */
            $token = $this->readCachedToken();

            /** Check expiry, throw it away if too old */
            if ($token) {
                if (! is_array($token) or empty($token['expiry']) or empty($token['token'])) {
                    /** Something wrong with it, get a new one. */
                    $token = null;
                } elseif ($this->wasRefused($token['token'])) {
                    /** A caller said it was refused, so it is no fallback either. */
                    $token = null;
                } else {
                    $expiry = strtotime($token['expiry']);
                    $timeForNew = $expiry - config('machine_token.time_before_expire');
                    if (time() > $timeForNew) {
                        /** time for a new token */
                        $this->oldToken = $token;
                        $token = null;
                    }
                }
            }

            if (! $token) {
                /** Retrieve new token and store in redis */
                $token = $this->retrieveFromAuth($deadline === null ? null : $deadline - $this->now());
                $this->writeCachedToken($token);
            }

            /** Cache in RAM for any other calls THIS request. */
            $this->tokenString = $token['token'];
        }

        return $this->tokenString;
    }

    /**
     * For a caller whose request was refused with the token this process last
     * returned (revoked or rotated at the issuer). Drops this process's copies
     * of it and nothing else: the next getToken() treats a cache entry holding
     * it, or a token refused earlier, as a miss and fetches a replacement,
     * which overwrites the entry, but uses a replacement another process has
     * already cached.
     * Nothing is deleted, so a late refusal cannot remove another process's
     * replacement, and the shared entry stays for other callers if the fetch
     * fails. Never throws.
     */
    public function invalidate(): void
    {
        if ($this->tokenString !== '') {
            $this->refused[$this->fingerprint($this->tokenString)] = true;
            $this->refused = array_slice($this->refused, -self::MAX_REFUSED, null, true);
        }

        $this->tokenString = '';
        $this->oldToken = null;
    }

    private function wasRefused(mixed $token): bool
    {
        return $this->refused !== [] && is_string($token) && isset($this->refused[$this->fingerprint($token)]);
    }

    private function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A cache read failure is a miss: the token is fetched from the auth endpoint. */
    private function readCachedToken(): mixed
    {
        try {
            return Cache::get(config('machine_token.redis_key'));
        } catch (Throwable $e) {
            $this->warn('machine_token_cache_failed', 'Machine token cache read failed: '.$e->getMessage(), 'cache_read_failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** A cache write failure keeps the fresh token in memory for this process. */
    private function writeCachedToken(array $token): void
    {
        try {
            Cache::put(config('machine_token.redis_key'), $token);
        } catch (Throwable $e) {
            $this->warn('machine_token_cache_failed', 'Machine token cache write failed: '.$e->getMessage(), 'cache_write_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A warning about a failure the service has already worked around. A
     * failing logger must not undo that, so its own exception is dropped.
     */
    private function warn(string $errorType, string $message, string $action, array $context = []): void
    {
        try {
            ErrorLogger::logWarning($errorType, $message, [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => $action,
                ...$context,
            ]);
        } catch (Throwable) {
            // The token path continues without the warning.
        }
    }

    /** Wall-clock seconds, from Carbon so tests can travel in time. */
    private function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }

    private function retrieveFromAuth(?float $timeout = null): array
    {
        $url = config('machine_token.url');
        $clientId = config('machine_token.client_id');
        $clientSecret = config('machine_token.secret');

        // Guard against missing configuration.
        if (! $url || ! $clientId || ! $clientSecret) {
            ErrorLogger::logWarning('machine_token_config_missing', 'Machine token configuration incomplete', [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => 'config_missing',
                'has_url' => ! empty($url),
                'has_client_id' => ! empty($clientId),
                'has_secret' => ! empty($clientSecret),
            ]);

            return $this->panic();
        }

        if ($timeout !== null && $timeout < self::MIN_TIMEOUT_SECONDS) {
            $this->warn('machine_token_budget_spent', 'Machine token fetch skipped: the caller\'s time budget is spent', 'budget_spent', [
                'budget_seconds' => $timeout,
            ]);

            return $this->panic();
        }

        if ($timeout !== null && ! CurlTransport::available()) {
            $this->warn('machine_token_curl_unavailable', 'Machine token fetch skipped: no cURL transport to bound it by the caller\'s time budget', 'curl_unavailable');

            return $this->panic();
        }

        try {
            $request = Http::accept('application/json');

            if ($timeout !== null) {
                // The total timeout covers the connection, so both share the budget.
                // Guzzle gives every redirect hop the full timeout again, so a
                // budgeted fetch follows none. cURL applies the timeout to the whole
                // transfer; Guzzle's PHP stream handler, used without cURL, applies
                // it to each read, which is why a budgeted fetch needs cURL.
                $request = $request->withoutRedirecting()->withOptions([
                    'connect_timeout' => $timeout,
                    'timeout' => $timeout,
                ]);
            }

            $response = $request->post($url, [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scopes' => '',
            ]);

            if ($response->failed()) {
                return $this->panic();
            }
        } catch (\Exception $e) {
            ErrorLogger::logWarning('machine_token_connection_failed', 'Failed to retrieve token from machine token service: '.$e->getMessage(), [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => 'connection_failed',
                'error' => $e->getMessage(),
            ]);

            return $this->panic();
        }

        $body = $response->body();
        $data = json_decode($body, true);

        if (! is_array($data) || empty($data['access_token']) || empty($data['expires_in'])) {
            return $this->panic();
        }

        $expiry = Carbon::createFromTimestamp(time() + $data['expires_in']);

        $token = [
            'token' => $data['access_token'],
            'expiry' => $expiry->toIso8601String(),
        ];

        return $token;
    }

    private function panic(): array
    {
        if ($this->oldToken) {
            /** Mild Panic */
            $expiry = strtotime($this->oldToken['expiry']);
            if (time() < $expiry) {
                /** Send message to slack to fix things before token really expires */
                ErrorLogger::logError('machine_token_refresh_failed', 'Could not get a new machine token. Current one expires: '.$this->oldToken['expiry'], [
                    LogFields::FEATURE => 'machine_token',
                    LogFields::ACTION => 'token_refresh_failed',
                    'token_expiry' => $this->oldToken['expiry'],
                ]);

                return $this->oldToken;
            }
        }
        /** Full Panic. We don't have an old token, or the old one has expired */
        throw new \RuntimeException('Could not get new machine token.');
    }
}
