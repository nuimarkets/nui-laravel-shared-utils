<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Logging;

use Illuminate\Http\Request;
use NuiMarkets\LaravelSharedUtils\Logging\EnvironmentProcessor;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use NuiMarkets\LaravelSharedUtils\Tests\Utils\LoggingTestHelpers;

class EnvironmentProcessorTest extends TestCase
{
    use LoggingTestHelpers;

    private array $trustedProxies;

    private int $trustedHeaderSet;

    private \ReflectionProperty $consoleFlag;

    private mixed $consoleFlagValue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->trustedProxies = Request::getTrustedProxies();
        $this->trustedHeaderSet = Request::getTrustedHeaderSet();

        // The processor only reads the request outside the console, and PHPUnit is the console.
        $this->consoleFlag = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $this->consoleFlagValue = $this->consoleFlag->getValue($this->app);
        $this->consoleFlag->setValue($this->app, false);
    }

    protected function tearDown(): void
    {
        $this->consoleFlag->setValue($this->app, $this->consoleFlagValue);
        Request::setTrustedProxies($this->trustedProxies, $this->trustedHeaderSet);
        parent::tearDown();
    }

    private function processWith(string $xff, array $trustedProxies): array
    {
        $request = Request::create('/api/orders', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.0.1.20',
            'HTTP_X_FORWARDED_FOR' => $xff,
        ]);
        Request::setTrustedProxies($trustedProxies, Request::HEADER_X_FORWARDED_FOR);
        $this->app->instance('request', $request);

        return (new EnvironmentProcessor)($this->createMonolog3Record())->extra;
    }

    public function test_request_ip_is_not_taken_from_a_spoofed_forwarded_header()
    {
        $extra = $this->processWith('string', ['10.0.1.20']);

        $this->assertSame('10.0.1.20', $extra['request.ip']);
    }

    public function test_request_ip_is_the_resolved_client_not_the_leftmost_entry()
    {
        $extra = $this->processWith('198.51.100.9, 203.0.113.42', ['10.0.1.20']);

        $this->assertSame('203.0.113.42', $extra['request.ip']);
    }

    public function test_request_ip_ignores_the_header_when_the_caller_is_not_trusted()
    {
        $extra = $this->processWith('203.0.113.42', []);

        $this->assertSame('10.0.1.20', $extra['request.ip']);
    }

    public function test_console_records_carry_no_request_fields()
    {
        $this->consoleFlag->setValue($this->app, true);

        $extra = $this->processWith('203.0.113.42', ['10.0.1.20']);

        $this->assertArrayNotHasKey('request.ip', $extra);
    }
}
