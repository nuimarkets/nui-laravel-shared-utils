<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers\Traits;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use NuiMarkets\LaravelSharedUtils\Auth\CacheAbility;

/**
 * Shared authorization for the cache-management endpoints.
 *
 * A request is authorized by either of two paths:
 *
 *  - Token: `?token=` matches `config('app.clear_cache_token')`, compared in
 *    constant time. Fails closed when the config value is unset, empty or not
 *    a string. Consumers wire it in `config/app.php` as
 *    `'clear_cache_token' => env('CLEAR_CACHE_TOKEN')` so the value survives
 *    `php artisan config:cache`.
 *  - User: the consumer has defined CacheAbility::MANAGE, the request
 *    carries an `Authorization: Bearer` header, `request()->user()` resolves
 *    to an object, and the gate allows that user the ability. Independent of
 *    the token config. A session or
 *    cookie alone never passes: both endpoints are GETs, which CSRF
 *    protection does not cover, and a browser attaches cookies to a
 *    cross-site GET but never an Authorization header. A guard that resolves
 *    null or a non-object (some return false) is denied without asking the
 *    gate, so a gate closure never sees one. A consumer that defines no
 *    such gate never reaches the user path: no user is resolved, so only the
 *    token can pass, a service with no auth guard configured still gets a
 *    401, and a Gate::before() or Gate::after() callback that grants every
 *    ability does not open it.
 *
 * The token is checked first, so a valid token is honoured without touching
 * the auth guard and a request carrying both is recorded as `token`.
 *
 * Used by ClearLadaAndResponseCacheController and ApplicationCacheController
 * so both endpoints share one auth contract.
 */
trait AuthorizesCacheOperations
{
    protected function isAuthorizedForDetailedInfo(): bool
    {
        return $this->cacheOperationAuthorization() !== null;
    }

    /**
     * Which path authorized the request, for the audit log, or null when
     * neither did. Never carries the token.
     *
     * @return array{authorized_by: 'token'}|array{authorized_by: 'user', user_id?: int|string}|null
     */
    protected function cacheOperationAuthorization(): ?array
    {
        if ($this->cacheTokenMatches()) {
            return ['authorized_by' => 'token'];
        }

        if (! Gate::has(CacheAbility::MANAGE) || ! $this->requestCarriesBearer()) {
            return null;
        }

        $user = request()->user();

        if (! is_object($user) || ! Gate::forUser($user)->allows(CacheAbility::MANAGE)) {
            return null;
        }

        $authorization = ['authorized_by' => 'user'];

        $userId = $this->cacheOperationUserId($user);

        if ($userId !== null) {
            $authorization['user_id'] = $userId;
        }

        return $authorization;
    }

    private function cacheTokenMatches(): bool
    {
        $configured = config('app.clear_cache_token');

        if (! is_string($configured) || $configured === '') {
            return false;
        }

        return hash_equals($configured, (string) request()->get('token', ''));
    }

    /**
     * Whether the request sent a non-empty bearer credential. The user path
     * requires one, so a user resolved from a session or cookie, which any
     * cross-site GET carries, never passes on its own.
     */
    private function requestCarriesBearer(): bool
    {
        $bearer = request()->bearerToken();

        return is_string($bearer) && $bearer !== '';
    }

    /**
     * The user's identifier: the auth identifier for an Authenticatable, else
     * a public `id` property (a token-decoded user object), else null.
     */
    private function cacheOperationUserId(mixed $user): int|string|null
    {
        $id = match (true) {
            $user instanceof Authenticatable => $user->getAuthIdentifier(),
            is_object($user) && isset($user->id) => $user->id,
            default => null,
        };

        return is_int($id) || is_string($id) ? $id : null;
    }
}
