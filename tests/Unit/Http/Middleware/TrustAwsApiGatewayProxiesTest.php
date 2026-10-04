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

    private function resolveIp(string $xff, string $remoteAddr = self::LB, ?string $region = 'ap-southeast-2', array $additional = [], ?array $ranges = null): ?string
    {
        $request = Request::create('/api/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $xff,
        ]);

        $middleware = new class($region, $additional, $ranges) extends TrustAwsApiGatewayProxies
        {
            private static ?array $testRanges = null;

            public function __construct(?string $region, array $additional, ?array $ranges)
            {
                $this->region = $region;
                $this->additionalProxies = $additional;
                self::$testRanges = $ranges;
            }

            public static function apiGatewayRanges(?string $region): array
            {
                return self::$testRanges ?? parent::apiGatewayRanges($region);
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

    public function test_caller_inside_the_gateway_ranges_is_returned_not_a_client_prepended_entry()
    {
        // The caller itself has a gateway address. Trusting the ranges wholesale would make
        // every entry trusted and fall back to the leftmost, which the client chose.
        $this->assertSame('3.26.139.200', $this->resolveIp('3.26.138.1, 3.26.139.200, '.self::GATEWAY));
    }

    public function test_caller_sharing_the_gateway_address_falls_back_to_the_gateway()
    {
        // Trusting the hop would strip both equal entries and expose the client-written one.
        $this->assertSame(self::GATEWAY, $this->resolveIp('198.51.100.9, '.self::GATEWAY.', '.self::GATEWAY));
    }

    public function test_caller_matching_the_gateway_hop_in_another_ipv6_spelling_falls_back()
    {
        // Equality must be by address, as Symfony compares, not by string.
        $this->assertSame('2001:db8::10', $this->resolveIp(
            '198.51.100.9, 2001:db8:0:0:0:0:0:10, 2001:db8::10',
            additional: [],
            ranges: ['2001:db8::/64'],
        ));
    }

    public function test_caller_sharing_the_load_balancer_address_falls_back_to_the_gateway()
    {
        $this->assertSame(self::GATEWAY, $this->resolveIp('198.51.100.9, '.self::LB.', '.self::GATEWAY));
    }

    public function test_caller_that_is_not_an_ip_falls_back_to_the_gateway()
    {
        // Symfony drops non-IP entries, which would expose the entry to their left.
        $this->assertSame(self::GATEWAY, $this->resolveIp('198.51.100.9, string, '.self::GATEWAY));
    }

    public function test_gateway_hop_with_no_caller_entry_falls_back_to_the_gateway()
    {
        $this->assertSame(self::GATEWAY, $this->resolveIp(self::GATEWAY));
    }

    public function test_a_gateway_address_the_client_sent_is_not_trusted_on_direct_traffic()
    {
        $this->assertSame('203.0.113.42', $this->resolveIp('198.51.100.9, '.self::GATEWAY.', 203.0.113.42'));
    }

    public function test_port_on_the_gateway_entry_is_tolerated()
    {
        $this->assertSame('203.0.113.42', $this->resolveIp('203.0.113.42, '.self::GATEWAY.':443'));
    }

    public function test_additional_proxies_left_of_the_gateway_are_skipped()
    {
        // client -> CDN -> API Gateway -> load balancer
        $this->assertSame('203.0.113.42', $this->resolveIp('203.0.113.42, 192.0.2.10, '.self::GATEWAY, additional: ['192.0.2.0/24']));
    }

    public function test_immediate_caller_is_read_from_the_request_not_the_global_server()
    {
        $previous = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.250';

        try {
            $this->assertSame('203.0.113.42', $this->resolveIp('203.0.113.42, '.self::GATEWAY));
        } finally {
            if ($previous === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previous;
            }
        }
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

    public function test_forwarded_headers_match_the_framework_middleware()
    {
        // Swapping this in for the framework middleware must not change URL generation.
        $server = [
            'REMOTE_ADDR' => self::LB,
            'HTTP_X_FORWARDED_PREFIX' => '/svc',
            'HTTP_X_FORWARDED_HOST' => 'api.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
        $resolve = fn (TrustProxies $middleware) => $middleware->handle(
            Request::create('/api/orders', 'GET', [], [], [], $server),
            fn (Request $req) => $req->getSchemeAndHttpHost().$req->getBaseUrl(),
        );

        $framework = $resolve(new class extends TrustProxies
        {
            protected $proxies = '*';
        });

        $this->assertSame($framework, $resolve(new TrustAwsApiGatewayProxies));
        $this->assertStringStartsWith('https://api.example.test', $framework);
    }

    public function test_bundled_ranges_cover_the_region()
    {
        $ranges = TrustAwsApiGatewayProxies::apiGatewayRanges('ap-southeast-2');

        $this->assertContains('3.26.138.0/23', $ranges);
        $this->assertSame([], TrustAwsApiGatewayProxies::apiGatewayRanges(null));
        $this->assertSame([], TrustAwsApiGatewayProxies::apiGatewayRanges('xx-nowhere-1'));
    }
}
