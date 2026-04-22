<?php

namespace NuiMarkets\LaravelSharedUtils\Providers;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use NuiMarkets\LaravelSharedUtils\Database\IamRdsConnector;

/**
 * Opts a Laravel application into IAM-based RDS authentication.
 *
 * When `config('iam-rds.auth_mode')` equals `'iam'`, the provider
 * registers replacement resolvers for the `mysql` and `pgsql` drivers
 * via `DB::extend`. Each resolver mints a fresh IAM auth token,
 * wires in the appropriate TLS options, and delegates to the
 * standard ConnectionFactory.
 *
 * When the config is unset (default), the provider is a no-op and
 * the application continues to use static DB_PASSWORD.
 */
class IamRdsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/iam-rds.php', 'iam-rds');

        $this->app->singleton(IamRdsConnector::class, function ($app): IamRdsConnector {
            $config = (array) $app['config']->get('iam-rds', []);

            $caBundlePath = $config['ca_bundle_path']
                ?? dirname(__DIR__, 2).'/resources/certs/aws-rds-global-bundle.pem';

            return new IamRdsConnector(
                region: (string) ($config['region'] ?? ''),
                caBundlePath: (string) $caBundlePath,
                tokenTtlSeconds: (int) ($config['token_ttl_seconds'] ?? 840),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/iam-rds.php' => config_path('iam-rds.php'),
            ], 'iam-rds-config');
        }

        if (config('iam-rds.auth_mode') !== 'iam') {
            return;
        }

        /** @var DatabaseManager $db */
        $db = $this->app['db'];
        $app = $this->app;

        $db->extend('mysql', static function (array $config, string $name) use ($app) {
            /** @var IamRdsConnector $iam */
            $iam = $app->make(IamRdsConnector::class);

            $config['password'] = $iam->tokenFor($config);
            // IAM's TLS options take precedence over app-provided options so a
            // stale/weakened PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=false cannot
            // silently disable cert verification under IAM auth. Matches the
            // pgsql path which forcibly sets sslmode=verify-full + sslrootcert.
            $config['options'] = $iam->mysqlPdoOptions() + ($config['options'] ?? []);

            return (new ConnectionFactory($app))->make($config, $name);
        });

        $db->extend('pgsql', static function (array $config, string $name) use ($app) {
            /** @var IamRdsConnector $iam */
            $iam = $app->make(IamRdsConnector::class);

            $config['password'] = $iam->tokenFor($config);
            $config = $iam->applyPgsqlSslToConfig($config);

            return (new ConnectionFactory($app))->make($config, $name);
        });
    }
}
