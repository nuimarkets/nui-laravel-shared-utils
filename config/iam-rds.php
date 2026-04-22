<?php

return [
    /*
    |--------------------------------------------------------------------------
    | IAM RDS Authentication
    |--------------------------------------------------------------------------
    |
    | Opt-in support for short-lived IAM auth tokens instead of static
    | DB_PASSWORD when connecting to Amazon RDS (or an RDS Proxy). When
    | `auth_mode` is set to `iam`, the IamRdsServiceProvider wraps the
    | default `mysql` and `pgsql` connection factories to mint a fresh
    | SigV4 token on each new connection and wire in RDS TLS.
    |
    | When `auth_mode` is unset (default), the package does nothing and
    | connections use whatever `DB_PASSWORD` provides. This preserves
    | backwards compatibility for consumers that have not opted in.
    |
    */

    'auth_mode' => env('IAM_RDS_AUTH_MODE'),

    'region' => env('IAM_RDS_REGION', env('AWS_DEFAULT_REGION', env('AWS_REGION', 'us-east-1'))),

    /*
    |--------------------------------------------------------------------------
    | TLS trust store
    |--------------------------------------------------------------------------
    |
    | Path to a PEM bundle that the driver will use to verify the server
    | certificate. When null/unset, the service provider falls back to
    | the global RDS truststore shipped with this package
    | (resources/certs/aws-rds-global-bundle.pem). Resolving the default
    | in the provider (not here) keeps the fallback correct after
    | `vendor:publish` copies this file into the consuming app's config/.
    |
    */

    'ca_bundle_path' => env('IAM_RDS_CA_BUNDLE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Token TTL
    |--------------------------------------------------------------------------
    |
    | How long (seconds) the connector will reuse a minted token before
    | minting a fresh one. RDS tokens are valid for 15 minutes; the
    | default here refreshes ~1 minute before expiry to absorb clock
    | drift and in-flight connections.
    |
    */

    'token_ttl_seconds' => (int) env('IAM_RDS_TOKEN_TTL_SECONDS', 840),
];
