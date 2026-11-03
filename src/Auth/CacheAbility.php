<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

/**
 * Gate ability names for the cache-management endpoints.
 *
 * A consuming service grants a user who calls with a bearer token the cache
 * actions by defining this ability, for example in a service provider's boot():
 *
 *     Gate::define(CacheAbility::MANAGE, fn ($user) => $user->isSuperAdmin());
 *
 * See AuthorizesCacheOperations for how the ability and the static token
 * combine. A class rather than a trait constant, because PHP cannot read a
 * constant through a trait name.
 */
final class CacheAbility
{
    /**
     * Clear the lada, response and application caches, or forget one
     * application-cache key.
     */
    public const MANAGE = 'manage-cache';

    private function __construct() {}
}
