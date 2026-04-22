<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use Illuminate\Support\Facades\DB;
use NuiMarkets\LaravelSharedUtils\Database\IamRdsConnector;
use NuiMarkets\LaravelSharedUtils\Providers\IamRdsServiceProvider;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PDO;

class IamRdsServiceProviderTest extends TestCase
{
    /** @var bool */
    private static $authMode = false;

    /** @var array<string, mixed>|null */
    private static $extraConnection = null;

    /** @var object|null */
    private static $fakeGenerator = null;

    /** @var bool */
    private static $nullifyCaBundle = false;

    protected function getPackageProviders($app): array
    {
        return [IamRdsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if (self::$authMode) {
            $app['config']->set('iam-rds.auth_mode', 'iam');
        }

        $app['config']->set('iam-rds.region', 'us-west-2');

        if (self::$nullifyCaBundle) {
            $app['config']->set('iam-rds.ca_bundle_path', null);
        } else {
            $app['config']->set('iam-rds.ca_bundle_path', '/tmp/test-bundle.pem');
        }

        if (self::$extraConnection !== null) {
            foreach (self::$extraConnection as $name => $cfg) {
                $app['config']->set("database.connections.{$name}", $cfg);
            }
        }

        if (self::$fakeGenerator !== null) {
            $fake = self::$fakeGenerator;
            $app->singleton(IamRdsConnector::class, fn () => new IamRdsConnector(
                region: 'us-west-2',
                caBundlePath: '/tmp/test-bundle.pem',
                tokenGeneratorFactory: fn () => $fake,
            ));
        }
    }

    protected function tearDown(): void
    {
        self::$authMode = false;
        self::$extraConnection = null;
        self::$fakeGenerator = null;
        self::$nullifyCaBundle = false;

        parent::tearDown();
    }

    private function makeFakeGenerator(): object
    {
        return new class
        {
            public int $calls = 0;

            public function createToken(string $endpoint, string $region, string $username): string
            {
                $this->calls++;

                return "fake-token:{$endpoint}:{$username}";
            }
        };
    }

    public function test_provider_is_noop_when_auth_mode_unset(): void
    {
        self::$authMode = false;
        self::$extraConnection = ['rds_mysql' => [
            'driver' => 'mysql',
            'host' => 'proxy.example.rds.amazonaws.com',
            'port' => 3306,
            'database' => 'appdb',
            'username' => 'app_user',
            'password' => 'static-secret',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]];
        $this->refreshApplication();

        $conn = DB::connection('rds_mysql');
        $cfg = $conn->getConfig();

        $this->assertSame('static-secret', $cfg['password']);
        $this->assertArrayNotHasKey('options', $cfg);
    }

    public function test_mysql_connection_receives_iam_token_and_ssl_options(): void
    {
        self::$authMode = true;
        self::$fakeGenerator = $this->makeFakeGenerator();
        self::$extraConnection = ['rds_mysql' => [
            'driver' => 'mysql',
            'host' => 'proxy.example.rds.amazonaws.com',
            'port' => 3306,
            'database' => 'appdb',
            'username' => 'app_user',
            'password' => 'ignored',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]];
        $this->refreshApplication();

        $conn = DB::connection('rds_mysql');
        $cfg = $conn->getConfig();

        $this->assertSame(
            'fake-token:proxy.example.rds.amazonaws.com:3306:app_user',
            $cfg['password']
        );
        $this->assertSame('/tmp/test-bundle.pem', $cfg['options'][PDO::MYSQL_ATTR_SSL_CA]);
        $this->assertTrue($cfg['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_mysql_iam_options_override_weakened_app_options(): void
    {
        self::$authMode = true;
        self::$fakeGenerator = $this->makeFakeGenerator();
        self::$extraConnection = ['rds_mysql' => [
            'driver' => 'mysql',
            'host' => 'proxy.example.rds.amazonaws.com',
            'port' => 3306,
            'database' => 'appdb',
            'username' => 'app_user',
            'password' => 'ignored',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [
                PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                PDO::MYSQL_ATTR_SSL_CA => '/tmp/app-weakened.pem',
            ],
        ]];
        $this->refreshApplication();

        $conn = DB::connection('rds_mysql');
        $cfg = $conn->getConfig();

        $this->assertTrue(
            $cfg['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT],
            'IAM mode must force cert verification even if app config tries to disable it.'
        );
        $this->assertSame(
            '/tmp/test-bundle.pem',
            $cfg['options'][PDO::MYSQL_ATTR_SSL_CA],
            'IAM mode must enforce its own CA bundle over any app-provided path.'
        );
    }

    public function test_pgsql_connection_receives_iam_token_and_ssl_params(): void
    {
        self::$authMode = true;
        self::$fakeGenerator = $this->makeFakeGenerator();
        self::$extraConnection = ['rds_pgsql' => [
            'driver' => 'pgsql',
            'host' => 'proxy.example.rds.amazonaws.com',
            'port' => 5432,
            'database' => 'appdb',
            'username' => 'app_user',
            'password' => 'ignored',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]];
        $this->refreshApplication();

        $conn = DB::connection('rds_pgsql');
        $cfg = $conn->getConfig();

        $this->assertSame(
            'fake-token:proxy.example.rds.amazonaws.com:5432:app_user',
            $cfg['password']
        );
        $this->assertSame('verify-full', $cfg['sslmode']);
        $this->assertSame('/tmp/test-bundle.pem', $cfg['sslrootcert']);
    }

    public function test_provider_publishes_config_file(): void
    {
        self::$authMode = false;
        $this->refreshApplication();

        $this->assertSame('us-west-2', config('iam-rds.region'));
        $this->assertSame('/tmp/test-bundle.pem', config('iam-rds.ca_bundle_path'));
    }

    public function test_connector_resolves_default_ca_bundle_relative_to_package_after_publish(): void
    {
        self::$authMode = false;
        self::$nullifyCaBundle = true;
        $this->refreshApplication();

        /** @var IamRdsConnector $iam */
        $iam = $this->app->make(IamRdsConnector::class);
        $bundle = $iam->mysqlPdoOptions()[PDO::MYSQL_ATTR_SSL_CA];

        $this->assertStringEndsWith(
            '/resources/certs/aws-rds-global-bundle.pem',
            $bundle,
            'Default CA bundle path must resolve inside the package after vendor:publish.'
        );
        $this->assertFileExists(
            $bundle,
            'Default CA bundle file should exist on disk; otherwise TLS will fail at runtime.'
        );
    }
}
