<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Logging\ErrorLogger;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;
use Throwable;

use function config;

class MachineTokenService implements MachineTokenServiceInterface
{
    /**
     * A budget shorter than this is treated as spent. Guzzle turns a timeout
     * under a millisecond into curl's 0, which means no timeout at all.
     */
    private const MIN_TIMEOUT_SECONDS = 0.01;

    /** Cache token for any subsequent calls to get within this request. */
    private string $tokenString = '';

    private ?array $oldToken = null;

    /**
     * Set by invalidate(): the next fetch goes to the auth endpoint even if the
     * cache still holds the refused token, for example because forget() failed.
     */
    private bool $skipCacheRead = false;

    /**
     * @param  float|null  $timeout  Seconds the caller can still spend. When given,
     *                               a fetch from the auth endpoint is bounded by it.
     *                               Null keeps the HTTP client's defaults.
     */
    public function getToken(?float $timeout = null): string
    {
        if ($this->tokenString === '') {
            /** Try redis, unless invalidate() said its copy was refused */
            $token = $this->skipCacheRead ? null : $this->readCachedToken();

            /** Check expiry, throw it away if too old */
            if ($token) {
                if (! is_array($token) or empty($token['expiry']) or empty($token['token'])) {
                    /** Something wrong with it, get a new one. */
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
                $token = $this->retrieveFromAuth($timeout);
                $this->skipCacheRead = false;
                $this->writeCachedToken($token);
            }

            /** Cache in RAM for any other calls THIS request. */
            $this->tokenString = $token['token'];
        }

        return $this->tokenString;
    }

    /**
     * Drop every copy of the current token, in memory and in the cache, so the
     * next getToken() fetches a new one. For a caller whose request was refused
     * with this token (revoked or rotated at the issuer). Never throws.
     */
    public function invalidate(): void
    {
        $this->tokenString = '';
        $this->oldToken = null;
        $this->skipCacheRead = true;

        try {
            Cache::forget(config('machine_token.redis_key'));
        } catch (Throwable $e) {
            ErrorLogger::logWarning('machine_token_cache_failed', 'Machine token cache forget failed: '.$e->getMessage(), [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => 'cache_forget_failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** A cache read failure is a miss: the token is fetched from the auth endpoint. */
    private function readCachedToken(): mixed
    {
        try {
            return Cache::get(config('machine_token.redis_key'));
        } catch (Throwable $e) {
            ErrorLogger::logWarning('machine_token_cache_failed', 'Machine token cache read failed: '.$e->getMessage(), [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => 'cache_read_failed',
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
            ErrorLogger::logWarning('machine_token_cache_failed', 'Machine token cache write failed: '.$e->getMessage(), [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => 'cache_write_failed',
                'error' => $e->getMessage(),
            ]);
        }
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
            ErrorLogger::logWarning('machine_token_budget_spent', 'Machine token fetch skipped: the caller\'s time budget is spent', [
                LogFields::FEATURE => 'machine_token',
                LogFields::ACTION => 'budget_spent',
                'budget_seconds' => $timeout,
            ]);

            return $this->panic();
        }

        try {
            $request = Http::accept('application/json');

            if ($timeout !== null) {
                // The total timeout covers the connection, so both share the budget.
                $request = $request->withOptions([
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
