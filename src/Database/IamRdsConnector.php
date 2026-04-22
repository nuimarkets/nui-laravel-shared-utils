<?php

namespace NuiMarkets\LaravelSharedUtils\Database;

use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Generates short-lived IAM auth tokens for Amazon RDS / RDS Proxy
 * connections and exposes the TLS wiring each driver needs.
 *
 * Tokens are cached per (host:port, username) pair and refreshed when
 * the configured TTL expires. The underlying AWS token generator is
 * resolved lazily on first use so that merely registering the provider
 * does not trigger the SDK credential-provider chain.
 */
class IamRdsConnector
{
    private string $region;

    private string $caBundlePath;

    private int $tokenTtlSeconds;

    /** @var Closure(): object */
    private Closure $tokenGeneratorFactory;

    /** @var Closure(): int */
    private Closure $clock;

    private ?object $tokenGenerator = null;

    /** @var array<string, array{token: string, expires_at: int}> */
    private array $tokenCache = [];

    /**
     * @param  Closure|null  $tokenGeneratorFactory  Factory returning an object with
     *                                               `createToken(string $endpoint, string $region, string $username): string`.
     *                                               Defaults to a lazy factory that instantiates
     *                                               `Aws\Rds\AuthTokenGenerator` with the SDK's default
     *                                               credential provider chain on first use.
     * @param  Closure|null  $clock  Optional time source override for testing. Must return unix seconds.
     */
    public function __construct(
        string $region,
        string $caBundlePath,
        int $tokenTtlSeconds = 840,
        ?Closure $tokenGeneratorFactory = null,
        ?Closure $clock = null,
    ) {
        if ($tokenTtlSeconds < 1) {
            throw new InvalidArgumentException('tokenTtlSeconds must be a positive integer.');
        }

        $this->region = $region;
        $this->caBundlePath = $caBundlePath;
        $this->tokenTtlSeconds = $tokenTtlSeconds;
        $this->tokenGeneratorFactory = $tokenGeneratorFactory ?? self::defaultTokenGeneratorFactory();
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Mint (or return a cached) IAM auth token for the given connection config.
     *
     * @param  array{host?: string, port?: int|string, username?: string}  $config
     */
    public function tokenFor(array $config): string
    {
        $host = (string) ($config['host'] ?? '');
        $port = (int) ($config['port'] ?? 0);
        $username = (string) ($config['username'] ?? '');

        if ($host === '' || $username === '') {
            throw new InvalidArgumentException(
                'IamRdsConnector::tokenFor requires host and username in the connection config.'
            );
        }

        $endpoint = $port > 0 ? "{$host}:{$port}" : $host;
        $cacheKey = "{$endpoint}|{$username}";
        $now = ($this->clock)();

        $cached = $this->tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expires_at'] > $now) {
            return $cached['token'];
        }

        $token = $this->tokenGenerator()->createToken($endpoint, $this->region, $username);

        $this->tokenCache[$cacheKey] = [
            'token' => $token,
            'expires_at' => $now + $this->tokenTtlSeconds,
        ];

        return $token;
    }

    /**
     * PDO options needed for a MySQL PDO to negotiate TLS against RDS.
     *
     * @return array<int, mixed>
     */
    public function mysqlPdoOptions(): array
    {
        return [
            PDO::MYSQL_ATTR_SSL_CA => $this->caBundlePath,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ];
    }

    /**
     * Merge the pgsql SSL params the Laravel Postgres connector inlines
     * into the DSN (`sslmode`, `sslrootcert`) into the given config.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function applyPgsqlSslToConfig(array $config): array
    {
        $config['sslmode'] = 'verify-full';
        $config['sslrootcert'] = $this->caBundlePath;

        return $config;
    }

    /**
     * Drop any cached tokens. Primarily useful for tests and for
     * consuming services that want to force a refresh.
     */
    public function clearCache(): void
    {
        $this->tokenCache = [];
    }

    private function tokenGenerator(): object
    {
        if ($this->tokenGenerator === null) {
            $this->tokenGenerator = ($this->tokenGeneratorFactory)();
        }

        return $this->tokenGenerator;
    }

    private static function defaultTokenGeneratorFactory(): Closure
    {
        return static function (): object {
            if (! class_exists(\Aws\Rds\AuthTokenGenerator::class)) {
                throw new RuntimeException(
                    'aws/aws-sdk-php is required for IAM RDS authentication. '
                    .'Install it with: composer require aws/aws-sdk-php'
                );
            }

            return new \Aws\Rds\AuthTokenGenerator(
                \Aws\Credentials\CredentialProvider::defaultProvider()
            );
        };
    }
}
