<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Contracts\MachineTokenServiceInterface;
use NuiMarkets\LaravelSharedUtils\Logging\ErrorLogger;
use NuiMarkets\LaravelSharedUtils\Logging\LogFields;

use function config;

class MachineTokenService implements MachineTokenServiceInterface
{
    /** Cache token for any subsequent calls to get within this request. */
    private string $tokenString = '';

    private ?array $oldToken = null;

    public function getToken(): string
    {
        if ($this->tokenString === '') {
            /** Try redis */
            $token = Cache::get(config('machine_token.redis_key'));

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
                $token = $this->retrieveFromAuth();
                Cache::put(config('machine_token.redis_key'), $token);
            }

            /** Cache in RAM for any other calls THIS request. */
            $this->tokenString = $token['token'];
        }

        return $this->tokenString;
    }

    private function retrieveFromAuth(): array
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

        try {
            $response = Http::accept('application/json')
                ->post($url, [
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
