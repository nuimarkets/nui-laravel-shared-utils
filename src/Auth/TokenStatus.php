<?php

namespace NuiMarkets\LaravelSharedUtils\Auth;

/**
 * What the token issuer said about an access token, or that it could not say.
 */
enum TokenStatus: string
{
    /** The issuer answered that the token is still active. */
    case Active = 'active';

    /** The issuer answered `active: false`. The only result that is a revocation. */
    case Revoked = 'revoked';

    /** No answer: the call failed, was refused, timed out or was skipped by the breaker. */
    case Unavailable = 'unavailable';
}
