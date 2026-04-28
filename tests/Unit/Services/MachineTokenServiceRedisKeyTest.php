<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use NuiMarkets\LaravelSharedUtils\Services\MachineTokenService;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class MachineTokenServiceRedisKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Cache::flush();

        Config::set('machine_token.redis_key', 'machine_token');
        Config::set('machine_token.time_before_expire', 300);
        Config::set('machine_token.url', 'https://auth.test.com/token');
        Config::set('machine_token.client_id', 'test_client_id');
        Config::set('machine_token.secret', 'test_client_secret');
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_successful_token_request_uses_literal_machine_token_cache_key()
    {
        Http::fake([
            'https://auth.test.com/token' => Http::response([
                'access_token' => 'new_jwt_token_12345',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = (new MachineTokenService)->getToken();

        $this->assertEquals('new_jwt_token_12345', $token);
        $this->assertTrue(Cache::has('machine_token'));
        $this->assertEquals('new_jwt_token_12345', Cache::get('machine_token')['token']);
    }
}
