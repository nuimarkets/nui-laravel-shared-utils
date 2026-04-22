# IAM RDS Connector

Opt-in support for authenticating Laravel database connections against Amazon RDS (or an RDS Proxy) with short-lived IAM auth tokens instead of a static `DB_PASSWORD`.

## What this gives you

- Mint a fresh SigV4-signed token (~15 minute TTL) on each new database connection
- Correct TLS wiring for MySQL (`PDO::MYSQL_ATTR_SSL_CA` + server cert verification) and Postgres (`sslmode=verify-full`, `sslrootcert`)
- The AWS RDS global CA truststore, committed with the package
- A service provider that wraps the default `mysql` and `pgsql` connection factories transparently: no edits to `config/database.php` in your application

## When it kicks in

The provider is a no-op unless `config('iam-rds.auth_mode')` equals `'iam'`. With the default `.env` (`IAM_RDS_AUTH_MODE` unset), your application keeps using static passwords exactly as before. Drop-in and opt-in by environment.

## Adoption steps in a consuming service

1. Bump this package in `composer.json` and `composer update nuimarkets/laravel-shared-utils`.

2. Register the service provider (this package ships no auto-discovered provider by design):

   ```php
   // config/app.php (Laravel 8-10) or bootstrap/providers.php (Laravel 11+)
   'providers' => [
       // ...
       NuiMarkets\LaravelSharedUtils\Providers\IamRdsServiceProvider::class,
   ],
   ```

3. Set the env flags:

   ```dotenv
   IAM_RDS_AUTH_MODE=iam
   DB_USERNAME=my-service                 # the IAM-mapped DB user
   DB_HOST=proxy.abc123.us-west-2.rds.amazonaws.com
   # DB_PASSWORD left unset or empty; the connector overrides it
   ```

4. Grant the service's task role (or IAM user) `rds-db:connect` on the relevant `dbuser` ARN. This lives in your infrastructure code, outside this package.

5. Smoke-test a web request, a queue worker, and a CLI command. Drop `DB_PASSWORD` once the service has been stable for a week.

No edits to `config/database.php` are required. The provider intercepts any connection whose driver is `mysql` or `pgsql` and injects the token + TLS params before the Laravel `ConnectionFactory` builds the connection.

## Configuration reference

All keys live under `iam-rds`. Publish the config with `php artisan vendor:publish --tag=iam-rds-config` if you want a local copy to edit; otherwise the package defaults apply.

| Key | Env var | Default | Notes |
| --- | --- | --- | --- |
| `auth_mode` | `IAM_RDS_AUTH_MODE` | `null` | Set to `iam` to enable. Anything else = no-op. |
| `region` | `IAM_RDS_REGION` | Falls back to `AWS_DEFAULT_REGION`, then `AWS_REGION`, then `us-east-1` | SigV4 signing region. |
| `ca_bundle_path` | `IAM_RDS_CA_BUNDLE_PATH` | `resources/certs/aws-rds-global-bundle.pem` inside this package | Override if you pin a regional bundle. |
| `token_ttl_seconds` | `IAM_RDS_TOKEN_TTL_SECONDS` | `840` | How long to reuse a minted token before refreshing. RDS accepts tokens for 15 minutes; the default refreshes ~1 minute before expiry. |

The config is read via `config('iam-rds.*')`, not `env()`, so it survives `php artisan config:cache`.

## How tokens are used

Tokens are generated per connection, not per query. Once a connection is open, RDS only checks the token during the TLS handshake; existing connections continue to work after the token expires. The connector caches tokens in-process keyed on `host:port|username` so repeated `DB::connection()` calls in the same process reuse a single token.

Long-lived processes (Horizon workers, daemonized queue workers) still refresh correctly: if the underlying connection drops and Laravel reconnects, the reconnection goes through `DB::extend` again and a fresh token is minted.

## Credentials

The default token-generator uses `Aws\Credentials\CredentialProvider::defaultProvider()`, so the SDK's normal resolution chain applies: ECS task role, EC2 instance profile, `AWS_*` env vars, `~/.aws/credentials`, etc. No custom wiring is needed for the standard AWS deployment patterns.

Token generator resolution is lazy. Merely registering the provider does not trigger the SDK credential chain; the generator is only instantiated on the first request that actually mints a token. This keeps `php artisan optimize` and test bootstraps cheap.

## TLS truststore refresh

The package ships the AWS RDS [global truststore bundle](https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem) under `resources/certs/aws-rds-global-bundle.pem`. AWS rotates CAs periodically (see the AWS RDS SSL/TLS documentation). When that happens:

1. Re-fetch the bundle from `https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem`
2. Commit the updated file to this repo
3. Cut a new release; consumers upgrade on their normal dependency cadence

Services that want to pin a regional truststore can override `IAM_RDS_CA_BUNDLE_PATH`.

## Testing

The `IamRdsConnector` class accepts an injectable token-generator factory (a `Closure` returning any object that exposes `createToken(string $endpoint, string $region, string $username): string`). Tests inject a stub and never call AWS:

```php
$stub = new class {
    public function createToken(string $endpoint, string $region, string $username): string
    {
        return "stub-token:{$endpoint}:{$username}";
    }
};

$connector = new IamRdsConnector(
    region: 'us-west-2',
    caBundlePath: '/tmp/bundle.pem',
    tokenGeneratorFactory: fn () => $stub,
);
```

Keep `IAM_RDS_AUTH_MODE` unset in `.env.testing`. The provider stays a no-op and standard PHPUnit + SQLite in-memory tests keep working unchanged.

## Troubleshooting

- **`aws/aws-sdk-php is required for IAM RDS authentication`**: The package requires `aws/aws-sdk-php ^3.342`. Install it with `composer require aws/aws-sdk-php`.
- **`SQLSTATE[HY000] [2002] SSL connection error`**: Verify the CA bundle path is readable and that the PHP process can open it. On containers, check the file made it into the image.
- **`SQLSTATE[HY000] [1045] Access denied`** with a long token-looking password: usually the IAM user is not granted `rds-db:connect` on the right `dbuser` ARN, or `DB_USERNAME` does not match the DB user created via `CREATE USER ... IDENTIFIED WITH AWSAuthenticationPlugin AS 'RDS'` (MySQL) / `GRANT rds_iam TO ...` (Postgres).
- **`Token length exceeds max length`**: IAM tokens are ~400 chars and require the `mysql_clear_password` client plugin over TLS. PHP 8.2+ with the bundled mysqlnd driver handles this cleanly; if you see this on an older PHP, upgrade.
