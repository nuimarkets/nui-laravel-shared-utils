<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature\Http\Controllers;

use NuiMarkets\LaravelSharedUtils\Http\Controllers\HomeController;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Tests for the basic health endpoint (GET /) response shape.
 *
 * Asserts the standardized payload (status / service / git_tag), the
 * debug-only conditional, and guards against the old shape leaking back
 * (no `message`, no `app_url`).
 */
class HomeControllerTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['router']->get('/', [HomeController::class, 'home']);

        // Deterministic service string for assertions.
        $app['config']->set('app.name', 'example-service');
        $app['config']->set('app.env', 'prod');
    }

    protected function tearDown(): void
    {
        putenv('GIT_TAG');
        unset($_ENV['GIT_TAG'], $_SERVER['GIT_TAG']);

        parent::tearDown();
    }

    /** @test */
    public function it_returns_the_standardized_health_payload()
    {
        putenv('GIT_TAG=v6.8.0');
        $_ENV['GIT_TAG'] = 'v6.8.0';

        config()->set('app.debug', false);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('service', 'example-service.prod');
        $response->assertJsonPath('git_tag', 'v6.8.0');
    }

    /** @test */
    public function it_returns_a_null_git_tag_when_unset()
    {
        // Explicitly clear so the test is deterministic regardless of test
        // order or an ambient GIT_TAG in the CI environment.
        putenv('GIT_TAG');
        unset($_ENV['GIT_TAG'], $_SERVER['GIT_TAG']);

        config()->set('app.debug', false);

        $response = $this->get('/');

        $response->assertStatus(200);
        // The key is always present; value is null when the env var is absent.
        $this->assertArrayHasKey('git_tag', $response->json());
        $response->assertJsonPath('git_tag', null);
    }

    /** @test */
    public function it_omits_debug_when_app_debug_is_false()
    {
        config()->set('app.debug', false);

        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('debug', $response->json());
    }

    /** @test */
    public function it_includes_debug_when_app_debug_is_true()
    {
        config()->set('app.debug', true);

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertJsonPath('debug', true);
    }

    /** @test */
    public function it_does_not_leak_the_old_response_shape()
    {
        config()->set('app.debug', true);

        $response = $this->get('/');

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertArrayNotHasKey('message', $payload);
        $this->assertArrayNotHasKey('app_url', $payload);
    }
}
