<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

/**
 * Immutable JWT user object for standardized authentication across services.
 *
 * This class provides a consistent interface for user data extracted from JWT tokens,
 * replacing ad-hoc stdClass usage with type-safe properties.
 */
class JWTUser
{
    /**
     * Create a new JWT user instance.
     *
     * @param  string  $id  User identifier
     * @param  string  $org_id  Organization identifier
     * @param  string  $role  User role (buyer, seller, admin, machine, etc.)
     * @param  string|null  $email  User email address
     * @param  string|null  $org_name  Organization name
     * @param  string|null  $org_type  Organization type
     */
    public function __construct(
        public readonly string $id,
        public readonly string $org_id,
        public readonly string $role,
        public readonly ?string $email = null,
        public readonly ?string $org_name = null,
        public readonly ?string $org_type = null,
    ) {}
}
