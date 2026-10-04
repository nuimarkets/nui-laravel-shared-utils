<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Http\Middleware\TrustAwsApiGatewayProxies;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class TrustAwsApiGatewayProxiesTest extends TestCase
{
    // Load balancer address as seen by the application.
    private const LB = '10.0.1.20';

    // Inside a published ap-southeast-2 API_GATEWAY range (3.26.138.0/23).
    private const GATEWAY = '3.26.139.234';

    private array $trustedProxies;

    private int $trustedHeaderSet;

    protected function setUp(): void
    {
        parent::setUp();
        // Trusted proxies are process-wide static state on the Symfony request.
        $this->trustedProxies = Request::getTrustedProxies();
        $this->trustedHeaderSet = Request::getTrustedHeaderSet();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->trustedProxies, $this->trustedHeaderSet);
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function resolveIp(string $xff, string $remoteAddr = self::LB, ?string $region = 'ap-southeast-2', array $additional = []): ?string
    {
        $request = Request::create('/api/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $xff,
        ]);

        $middleware = new class($region, $additional) extends TrustAwsApiGatewayProxies
        {
            public function __construct(?string $region, array $additional)
            {
                $this->region = $region;
                $this->additionalProxies = $additional;
            }
        };

        return $middleware->handle($request, fn (Request $req) => $req->ip());
    }

    public function test_returns_the_address_api_gateway_appended()
    {
        $this->assertSame('203.0.113.42', $this->resolveIp('203.0.113.42, '.self::GATEWAY));
    }

    public function test_ignores_entries_the_client_prepended()
    {
        $this->assertSame('203.0.113.42', $this->resolveIp('198.51.100.9, 203.0.113.42, '.self::GATEWAY));
    }

    public function test_drops_non_ip_values_the_client_sent()
    {
        $this->assertSame('203.0.113.42', $this->resolveIp('string, 203.0.113.42, '.self::GATEWAY));
    }

    public function test_direct_load_balancer_traffic_returns_the_load_balancer_appended_address()
    {
        // No gateway hop: the rightmost entry is the caller, and the spoofed one is ignored.
        $this->assertSame('203.0.113.42', $this->resolveIp('198.51.100.9, 203.0.113.42'));
    }

    public function test_unknown_region_trusts_only_the_immediate_caller()
    {
        $this->assertSame(self::GATEWAY, $this->resolveIp('203.0.113.42, '.self::GATEWAY, region: 'xx-nowhere-1'));
    }

    public function test_gateway_range_from_another_region_is_not_trusted()
    {
        $this->assertSame(self::GATEWAY, $this->resolveIp('203.0.113.42, '.self::GATEWAY, region: 'us-east-1'));
    }

    public function test_additional_proxies_are_trusted()
    {
        $this->assertSame('203.0.113.42', $this->resolveIp('203.0.113.42, 192.0.2.10', region: null, additional: ['192.0.2.0/24']));
    }

    public function test_region_falls_back_to_the_environment()
    {
        // Env reads $_SERVER and $_ENV before getenv(), so an ambient AWS_REGION would win.
        $saved = [getenv('AWS_REGION'), $_SERVER['AWS_REGION'] ?? null, $_ENV['AWS_REGION'] ?? null];
        putenv('AWS_REGION=ap-southeast-2');
        $_SERVER['AWS_REGION'] = $_ENV['AWS_REGION'] = 'ap-southeast-2';

        try {
            $this->assertSame('203.0.113.42', $this->resolveIp('203.0.113.42, '.self::GATEWAY, region: null));
        } finally {
            $saved[0] === false ? putenv('AWS_REGION') : putenv("AWS_REGION={$saved[0]}");
            if ($saved[1] === null) {
                unset($_SERVER['AWS_REGION']);
            } else {
                $_SERVER['AWS_REGION'] = $saved[1];
            }
            if ($saved[2] === null) {
                unset($_ENV['AWS_REGION']);
            } else {
                $_ENV['AWS_REGION'] = $saved[2];
            }
        }
    }

    public function test_static_at_override_does_not_widen_trust()
    {
        // A framework-level trustProxies(at: '**') would trust every hop and return the
        // leftmost, client-controlled entry.
        TrustProxies::at('**');

        $this->assertSame('203.0.113.42', $this->resolveIp('198.51.100.9, 203.0.113.42, '.self::GATEWAY));
    }

    public function test_inherits_the_framework_forwarded_headers()
    {
        // Swapping this in for the framework middleware must not drop X-Forwarded-Prefix.
        $request = Request::create('/api/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => self::LB,
            'HTTP_X_FORWARDED_PREFIX' => '/svc',
        ]);

        $baseUrl = (new TrustAwsApiGatewayProxies)->handle($request, fn (Request $req) => $req->getBaseUrl());

        $this->assertSame('/svc', $baseUrl);
    }

    public function test_bundled_ranges_cover_the_region()
    {
        $ranges = TrustAwsApiGatewayProxies::apiGatewayRanges('ap-southeast-2');

        $this->assertContains('3.26.138.0/23', $ranges);
        $this->assertSame([], TrustAwsApiGatewayProxies::apiGatewayRanges(null));
        $this->assertSame([], TrustAwsApiGatewayProxies::apiGatewayRanges('xx-nowhere-1'));
    }
}
