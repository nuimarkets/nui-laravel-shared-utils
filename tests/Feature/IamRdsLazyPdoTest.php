<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Rds\AuthTokenGenerator;
use GuzzleHttp\Promise\Create;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use NuiMarkets\LaravelSharedUtils\Database\IamRdsConnector;
use NuiMarkets\LaravelSharedUtils\Providers\IamRdsServiceProvider;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IamRdsLazyPdoTest extends TestCase
{
    private Application $app;

    private object $driver;

    private object $clock;

    private object $signer;

    private function bootProvider(?string $mode, string $driver = 'mysql'): void
    {
        $this->app = new Application(sys_get_temp_dir());
        $this->app->instance('config', new Repository([
            'app' => ['name' => 'lazy-pdo-test', 'env' => 'testing'],
            'database' => ['default' => 'primary', 'connections' => [
                'primary' => $this->connection('appdb', $driver),
                'secondary' => $this->connection('reference', $driver),
            ]],
            'iam-rds' => ['auth_mode' => $mode, 'region' => 'ap-southeast-2'],
        ]));
        $this->driver = new class
        {
            public array $configs = [];

            public function connect(array $config): PDO
            {
                $this->configs[] = $config;

                return new PDO('sqlite::memory:');
            }
        };
        $this->clock = (object) ['now' => 1000];
        $this->signer = new class
        {
            public int $calls = 0;

            public function createToken(string $endpoint, string $region, string $user): string
            {
                return 'synthetic-token-'.(++$this->calls).':'.$endpoint.':'.$region.':'.$user;
            }
        };
        $this->app->register(DatabaseServiceProvider::class);
        $this->app->register(IamRdsServiceProvider::class);
        $this->app->instance('db.connector.'.$driver, $this->driver);
        $this->app->instance(IamRdsConnector::class, new IamRdsConnector(
            'ap-southeast-2',
            dirname(__DIR__, 2).'/resources/certs/aws-rds-global-bundle.pem',
            tokenGeneratorFactory: fn () => $this->signer,
            clock: fn () => $this->clock->now,
        ));
        $this->app->boot();
    }

    private function connection(string $database, string $driver = 'mysql'): array
    {
        return ['driver' => $driver, 'host' => 'writer.example.rds.amazonaws.com', 'port' => $driver === 'pgsql' ? 5432 : 3306,
            'database' => $database, 'username' => 'app_user', 'password' => 'synthetic-password',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''];
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->app->flush();
        }
        Container::setInstance(null);
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function test_unset_mode_preserves_password_and_never_signs(string $driver): void
    {
        $this->bootProvider(null, $driver);
        $this->app['db']->connection()->getPdo();
        self::assertSame('synthetic-password', $this->driver->configs[0]['password']);
        self::assertSame(0, $this->signer->calls);
    }

    #[DataProvider('drivers')]
    public function test_explicit_password_mode_preserves_both_connections(string $driver): void
    {
        $this->bootProvider('password', $driver);
        foreach (['primary', 'secondary'] as $name) {
            $this->app['db']->connection($name)->getPdo();
        }
        self::assertCount(2, $this->driver->configs);
        self::assertSame(0, $this->signer->calls);
        self::assertSame(['synthetic-password', 'synthetic-password'], array_column($this->driver->configs, 'password'));
    }

    public function test_iam_covers_both_connections_and_forces_verified_tls(): void
    {
        $this->bootProvider('iam');
        $this->app['config']->set('database.connections.secondary.options', [PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false]);
        foreach (['primary', 'secondary'] as $name) {
            $this->app['db']->connection($name)->getPdo();
        }
        self::assertSame(['appdb', 'reference'], array_column($this->driver->configs, 'database'));
        foreach ($this->driver->configs as $config) {
            self::assertStringContainsString(':ap-southeast-2:app_user', $config['password']);
            self::assertTrue($config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
            self::assertFileExists($config['options'][PDO::MYSQL_ATTR_SSL_CA]);
        }
        self::assertSame(1, $this->signer->calls);
    }

    #[DataProvider('drivers')]
    public function test_reconnect_after_token_expiry_resigns_for_each_connection(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        foreach (['primary', 'secondary'] as $name) {
            $connection = $this->app['db']->connection($name);
            $connection->getPdo();
            $before = end($this->driver->configs)['password'];
            $this->clock->now += 901;
            $connection->disconnect();
            self::assertSame(1, (int) $connection->selectOne('SELECT 1 AS n')->n);
            self::assertNotSame($before, end($this->driver->configs)['password']);
        }
        self::assertSame(3, $this->signer->calls);
    }

    public function test_refreshes_at_the_840_second_boundary(): void
    {
        $this->bootProvider('iam');
        $iam = $this->app->make(IamRdsConnector::class);
        $config = $this->connection('appdb');
        $first = $iam->tokenFor($config);
        $this->clock->now += 839;
        self::assertSame($first, $iam->tokenFor($config));
        $this->clock->now++;
        self::assertNotSame($first, $iam->tokenFor($config));
    }

    public function test_sdk_memoized_provider_refreshes_expired_role_credentials(): void
    {
        $calls = 0;
        $firstCredentials = new class('SYNTHETICKEY1', 'synthetic-secret', 'synthetic-session') extends Credentials
        {
            public bool $expired = false;

            public function getExpiration()
            {
                return $this->expired ? time() - 1 : time() + 3600;
            }

            public function isExpired()
            {
                return $this->expired;
            }
        };
        $provider = CredentialProvider::memoize(function () use (&$calls, $firstCredentials) {
            $calls++;

            return Create::promiseFor($calls === 1 ? $firstCredentials : new Credentials(
                'SYNTHETICKEY2', 'synthetic-secret', 'synthetic-session-2', time() + 3600));
        });
        $signer = new AuthTokenGenerator($provider);
        $first = $signer->createToken('writer.example.rds.amazonaws.com:3306', 'ap-southeast-2', 'app_user');
        $firstCredentials->expired = true;
        $second = $signer->createToken('writer.example.rds.amazonaws.com:3306', 'ap-southeast-2', 'app_user');
        self::assertSame(2, $calls);
        self::assertStringContainsString('SYNTHETICKEY1', $first);
        self::assertStringContainsString('SYNTHETICKEY2', $second);
        self::assertNotSame($first, $second);
    }

    public static function drivers(): array
    {
        return [['mysql'], ['pgsql']];
    }

    #[DataProvider('drivers')]
    public function test_delayed_first_pdo_does_not_capture_an_early_token(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        $connection = $this->app['db']->connection('secondary');
        self::assertSame(0, $this->signer->calls);
        self::assertCount(0, $this->driver->configs);
        self::assertNull($connection->getConfig('password'));
        $this->clock->now += 901;
        $connection->getPdo();
        self::assertSame(1, $this->signer->calls);
        self::assertSame('secondary', $connection->getName());
        self::assertSame('reference', $connection->getDatabaseName());
        self::assertInstanceOf($driver === 'pgsql'
            ? PostgresConnection::class
            : MySqlConnection::class, $connection);
    }

    #[DataProvider('drivers')]
    public function test_delayed_first_pdo_replaces_a_cached_token_from_another_connection(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        $this->app['db']->connection()->getPdo();
        $before = $this->driver->configs[0]['password'];
        $connection = $this->app['db']->connection('secondary');
        $this->clock->now += 901;
        $connection->getPdo();
        self::assertSame(2, $this->signer->calls);
        self::assertNotSame($before, $this->driver->configs[1]['password']);
        self::assertNull($connection->getConfig('password'));
    }

    #[DataProvider('drivers')]
    public function test_explicit_reconnect_can_delay_pdo_beyond_token_expiry(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        $connection = $this->app['db']->connection();
        $connection->getPdo();
        $before = $this->driver->configs[0]['password'];
        self::assertSame($connection, $this->app['db']->reconnect());
        self::assertCount(1, $this->driver->configs);
        $this->clock->now += 901;
        $connection->getPdo();
        self::assertSame(2, $this->signer->calls);
        self::assertNotSame($before, $this->driver->configs[1]['password']);
    }

    #[DataProvider('drivers')]
    public function test_failed_pdo_can_retry_later_with_current_credentials(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        $connector = new class
        {
            public array $configs = [];

            public function connect(array $config): PDO
            {
                $this->configs[] = $config;
                if (count($this->configs) === 1) {
                    throw new PDOException('synthetic connection failure');
                }

                return new PDO('sqlite::memory:');
            }
        };
        $this->app->instance('db.connector.'.$driver, $connector);
        $connection = $this->app['db']->connection();
        try {
            $connection->getPdo();
            self::fail('First opening must fail');
        } catch (PDOException $e) {
            self::assertSame('synthetic connection failure', $e->getMessage());
        }
        $this->clock->now += 901;
        $connection->getPdo();
        self::assertSame(2, $this->signer->calls);
        self::assertNotSame($connector->configs[0]['password'], $connector->configs[1]['password']);
    }

    #[DataProvider('drivers')]
    public function test_signing_failure_on_a_query_is_wrapped_and_the_connection_recovers(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        $flag = (object) ['fail' => true];
        $signer = $this->signer;
        $this->app->instance(IamRdsConnector::class, new IamRdsConnector(
            'ap-southeast-2', '/synthetic-ca.pem',
            tokenGeneratorFactory: fn () => new class($flag, $signer)
            {
                public function __construct(public object $flag, public object $inner) {}

                public function createToken(string $endpoint, string $region, string $user): string
                {
                    if ($this->flag->fail) {
                        throw new \RuntimeException('synthetic credential failure');
                    }

                    return $this->inner->createToken($endpoint, $region, $user);
                }
            },
            clock: fn () => $this->clock->now,
        ));
        $connection = $this->app['db']->connection();
        try {
            $connection->selectOne('SELECT 1 AS n');
            self::fail('Signing failure must surface');
        } catch (QueryException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            self::assertCount(0, $this->driver->configs);
        }
        $flag->fail = false;
        self::assertSame(1, (int) $connection->selectOne('SELECT 1 AS n')->n);
        self::assertSame(1, $this->signer->calls);
    }

    #[DataProvider('drivers')]
    public function test_unsupported_shapes_fail_before_signing(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        foreach (['host' => ['one.example', 'two.example'], 'read' => ['host' => 'reader.example']] as $key => $value) {
            $config = $this->connection('appdb', $driver);
            $config[$key] = $value;
            $this->app['config']->set('database.connections.invalid', $config);
            try {
                $this->app['db']->connection('invalid');
                self::fail('Unsupported shape must fail before PDO resolution');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($key === 'host' ? 'single host' : 'read/write split', $e->getMessage());
            }
        }
        self::assertSame(0, $this->signer->calls);
        self::assertCount(0, $this->driver->configs);
    }

    public function test_pgsql_forces_verified_tls_and_keeps_connector_options(): void
    {
        $this->bootProvider('iam', 'pgsql');
        $this->app['config']->set('database.connections.primary.sslmode', 'disable');
        $this->app['config']->set('database.connections.primary.sslrootcert', '/wrong.pem');
        $this->app['config']->set('database.connections.primary.application_name', 'sample');
        $this->app['db']->connection()->getPdo();
        $config = $this->driver->configs[0];
        self::assertSame('verify-full', $config['sslmode']);
        self::assertFileExists($config['sslrootcert']);
        self::assertSame('sample', $config['application_name']);
        self::assertStringContainsString(':5432:ap-southeast-2:app_user', $config['password']);
    }

    #[DataProvider('drivers')]
    public function test_provider_uses_rotated_sdk_credentials_on_later_pdo(string $driver): void
    {
        $this->bootProvider('iam', $driver);
        $calls = 0;
        $credentials = new class('SYNTHETICKEY1', 'synthetic-secret', 'synthetic-session') extends Credentials
        {
            public bool $expired = false;

            public function getExpiration()
            {
                return $this->expired ? time() - 1 : time() + 3600;
            }

            public function isExpired()
            {
                return $this->expired;
            }
        };
        $provider = CredentialProvider::memoize(function () use (&$calls, $credentials) {
            return Create::promiseFor(++$calls === 1 ? $credentials
                : new Credentials('SYNTHETICKEY2', 'synthetic-secret', 'synthetic-session-2', time() + 3600));
        });
        $this->app->instance(IamRdsConnector::class, new IamRdsConnector(
            'ap-southeast-2', '/synthetic-ca.pem',
            tokenGeneratorFactory: fn () => new AuthTokenGenerator($provider),
            clock: fn () => $this->clock->now,
        ));
        $this->app['db']->connection()->getPdo();
        $connection = $this->app['db']->connection('secondary');
        $credentials->expired = true;
        $this->clock->now += 901;
        $connection->getPdo();
        self::assertSame(2, $calls);
        self::assertStringContainsString('SYNTHETICKEY1', $this->driver->configs[0]['password']);
        self::assertStringContainsString('SYNTHETICKEY2', $this->driver->configs[1]['password']);
    }
}
