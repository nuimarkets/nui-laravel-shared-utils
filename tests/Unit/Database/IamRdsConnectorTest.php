<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Database;

use InvalidArgumentException;
use NuiMarkets\LaravelSharedUtils\Database\IamRdsConnector;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PDO;

class IamRdsConnectorTest extends TestCase
{
    private function stubGenerator(): object
    {
        return new class
        {
            public int $calls = 0;

            /** @var array<int, array{endpoint: string, region: string, username: string}> */
            public array $seen = [];

            public function createToken(string $endpoint, string $region, string $username): string
            {
                $this->calls++;
                $this->seen[] = compact('endpoint', 'region', 'username');

                return "token:{$endpoint}:{$username}:{$this->calls}";
            }
        };
    }

    public function test_token_for_mints_token_with_endpoint_region_and_username(): void
    {
        $gen = $this->stubGenerator();
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenGeneratorFactory: fn () => $gen,
        );

        $token = $connector->tokenFor([
            'host' => 'proxy.example.rds.amazonaws.com',
            'port' => 3306,
            'username' => 'app_user',
        ]);

        $this->assertSame('token:proxy.example.rds.amazonaws.com:3306:app_user:1', $token);
        $this->assertCount(1, $gen->seen);
        $this->assertSame([
            'endpoint' => 'proxy.example.rds.amazonaws.com:3306',
            'region' => 'us-west-2',
            'username' => 'app_user',
        ], $gen->seen[0]);
    }

    public function test_token_for_caches_tokens_within_ttl(): void
    {
        $gen = $this->stubGenerator();
        $now = 1000;
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenTtlSeconds: 60,
            tokenGeneratorFactory: fn () => $gen,
            clock: function () use (&$now) {
                return $now;
            },
        );

        $config = ['host' => 'h', 'port' => 3306, 'username' => 'u'];

        $first = $connector->tokenFor($config);
        $now += 30; // still within TTL
        $second = $connector->tokenFor($config);

        $this->assertSame($first, $second);
        $this->assertSame(1, $gen->calls);
    }

    public function test_token_for_refreshes_after_ttl_expires(): void
    {
        $gen = $this->stubGenerator();
        $now = 1000;
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenTtlSeconds: 60,
            tokenGeneratorFactory: fn () => $gen,
            clock: function () use (&$now) {
                return $now;
            },
        );

        $config = ['host' => 'h', 'port' => 3306, 'username' => 'u'];

        $first = $connector->tokenFor($config);
        $now += 61; // TTL expired
        $second = $connector->tokenFor($config);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $gen->calls);
    }

    public function test_token_cache_is_keyed_on_endpoint_and_username(): void
    {
        $gen = $this->stubGenerator();
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenGeneratorFactory: fn () => $gen,
        );

        $connector->tokenFor(['host' => 'h1', 'port' => 3306, 'username' => 'u']);
        $connector->tokenFor(['host' => 'h2', 'port' => 3306, 'username' => 'u']);
        $connector->tokenFor(['host' => 'h1', 'port' => 3306, 'username' => 'other']);
        $connector->tokenFor(['host' => 'h1', 'port' => 3306, 'username' => 'u']); // cached

        $this->assertSame(3, $gen->calls);
    }

    public function test_token_for_requires_host_and_username(): void
    {
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenGeneratorFactory: fn () => $this->stubGenerator(),
        );

        $this->expectException(InvalidArgumentException::class);
        $connector->tokenFor(['host' => '', 'username' => 'u']);
    }

    public function test_token_generator_factory_is_lazy(): void
    {
        $invoked = false;
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenGeneratorFactory: function () use (&$invoked) {
                $invoked = true;

                return $this->stubGenerator();
            },
        );

        // Options helpers must not resolve the generator.
        $connector->mysqlPdoOptions();
        $connector->applyPgsqlSslToConfig([]);
        $this->assertFalse($invoked, 'Factory should not be invoked before tokenFor()');

        $connector->tokenFor(['host' => 'h', 'port' => 3306, 'username' => 'u']);
        $this->assertTrue($invoked);
    }

    public function test_mysql_pdo_options_returns_ssl_ca_and_verify(): void
    {
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/path/to/bundle.pem',
            tokenGeneratorFactory: fn () => $this->stubGenerator(),
        );

        $this->assertSame([
            PDO::MYSQL_ATTR_SSL_CA => '/path/to/bundle.pem',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ], $connector->mysqlPdoOptions());
    }

    public function test_apply_pgsql_ssl_to_config_sets_verify_full_and_rootcert(): void
    {
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/path/to/bundle.pem',
            tokenGeneratorFactory: fn () => $this->stubGenerator(),
        );

        $out = $connector->applyPgsqlSslToConfig(['host' => 'h', 'port' => 5432]);

        $this->assertSame('verify-full', $out['sslmode']);
        $this->assertSame('/path/to/bundle.pem', $out['sslrootcert']);
        $this->assertSame('h', $out['host']);
    }

    public function test_clear_cache_forces_refresh(): void
    {
        $gen = $this->stubGenerator();
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenGeneratorFactory: fn () => $gen,
        );

        $config = ['host' => 'h', 'port' => 3306, 'username' => 'u'];
        $connector->tokenFor($config);
        $connector->clearCache();
        $connector->tokenFor($config);

        $this->assertSame(2, $gen->calls);
    }

    public function test_constructor_rejects_non_positive_ttl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenTtlSeconds: 0,
        );
    }

    public function test_endpoint_omits_port_when_missing(): void
    {
        $gen = $this->stubGenerator();
        $connector = new IamRdsConnector(
            region: 'us-west-2',
            caBundlePath: '/tmp/bundle.pem',
            tokenGeneratorFactory: fn () => $gen,
        );

        $connector->tokenFor(['host' => 'proxy.example.rds.amazonaws.com', 'username' => 'u']);

        $this->assertSame('proxy.example.rds.amazonaws.com', $gen->seen[0]['endpoint']);
    }
}
