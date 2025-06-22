<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Support;

use Illuminate\Support\Facades\Log;
use Nuimarkets\LaravelSharedUtils\Support\ProfilingTrait;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class ProfilingTraitTest extends TestCase
{
    private $testClass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testClass = new class
        {
            use ProfilingTrait;

            public function publicProfileStart($method)
            {
                return $this->profileStart($method);
            }

            public function publicProfileEnd($method, $startTime)
            {
                return $this->profileEnd($method, $startTime);
            }

            public function publicLogTimings()
            {
                return self::logTimings();
            }

            public function publicInitProfiling()
            {
                return self::initProfiling();
            }
        };

        // Initialize profiling for each test
        $this->testClass->publicInitProfiling();
    }

    public function test_profile_start_returns_current_time()
    {
        $startTime = $this->testClass->publicProfileStart('test_method');

        $this->assertIsFloat($startTime);
        $this->assertGreaterThan(0, $startTime);
    }

    public function test_profile_end_records_timing_data()
    {
        $startTime = microtime(true);

        // Sleep for a small amount to ensure measurable time difference
        usleep(1000); // 1ms

        $this->testClass->publicProfileEnd('test_method', $startTime);

        // Since we can't easily access static properties, we'll test via logging
        Log::shouldReceive('debug')
            ->once()
            ->with('Remote repository timing', \Mockery::on(function ($data) {
                return isset($data['class']) &&
                       isset($data['total_seconds']) &&
                       isset($data['calls']) &&
                       isset($data['calls_breakdown']) &&
                       $data['calls'] === 1 &&
                       count($data['calls_breakdown']) === 1;
            }));

        $this->testClass->publicLogTimings();
    }

    public function test_multiple_calls_accumulate_timing_data()
    {
        // First call
        $startTime1 = microtime(true);
        usleep(1000);
        $this->testClass->publicProfileEnd('method_a', $startTime1);

        // Second call
        $startTime2 = microtime(true);
        usleep(1000);
        $this->testClass->publicProfileEnd('method_b', $startTime2);

        // Third call to same method
        $startTime3 = microtime(true);
        usleep(1000);
        $this->testClass->publicProfileEnd('method_a', $startTime3);

        Log::shouldReceive('debug')
            ->once()
            ->with('Remote repository timing', \Mockery::on(function ($data) {
                return $data['calls'] === 3 && // Total calls across all methods
                       count($data['calls_breakdown']) === 3; // All individual calls recorded
            }));

        $this->testClass->publicLogTimings();
    }

    public function test_log_timings_calculates_percentages()
    {
        $startTime = microtime(true);
        usleep(2000); // 2ms
        $this->testClass->publicProfileEnd('slow_method', $startTime);

        Log::shouldReceive('debug')
            ->once()
            ->with('Remote repository timing', \Mockery::on(function ($data) {
                return isset($data['request_percentage']) &&
                       str_contains($data['request_percentage'], '%') &&
                       $data['total_seconds'] > 0;
            }));

        $this->testClass->publicLogTimings();
    }

    public function test_log_timings_handles_no_data_gracefully()
    {
        // Don't record any timing data, just call logTimings
        Log::shouldReceive('debug')->never();

        $this->testClass->publicLogTimings();

        // Should not throw any exceptions
        $this->assertTrue(true);
    }

    public function test_init_profiling_resets_state()
    {
        // Add some timing data
        $startTime = microtime(true);
        usleep(1000);
        $this->testClass->publicProfileEnd('test_method', $startTime);

        // Reset profiling
        $this->testClass->publicInitProfiling();

        // Should not log anything since we reset
        Log::shouldReceive('debug')->never();

        $this->testClass->publicLogTimings();
    }

    public function test_profiling_trait_methods_exist()
    {
        $this->assertTrue(method_exists($this->testClass, 'profileStart'));
        $this->assertTrue(method_exists($this->testClass, 'profileEnd'));
        $this->assertTrue(method_exists($this->testClass, 'logTimings'));
        $this->assertTrue(method_exists($this->testClass, 'initProfiling'));
    }

    public function test_calls_breakdown_contains_method_details()
    {
        $startTime1 = microtime(true);
        usleep(1000);
        $this->testClass->publicProfileEnd('get', $startTime1);

        $startTime2 = microtime(true);
        usleep(2000);
        $this->testClass->publicProfileEnd('post', $startTime2);

        Log::shouldReceive('debug')
            ->once()
            ->with('Remote repository timing', \Mockery::on(function ($data) {
                $breakdown = $data['calls_breakdown'];

                return count($breakdown) === 2 &&
                       $breakdown[0]['method'] === 'get' &&
                       $breakdown[1]['method'] === 'post' &&
                       isset($breakdown[0]['seconds']) &&
                       isset($breakdown[1]['seconds']) &&
                       $breakdown[1]['seconds'] > $breakdown[0]['seconds']; // post took longer
            }));

        $this->testClass->publicLogTimings();
    }

    public function test_profiling_with_real_world_scenario()
    {
        // Simulate typical RemoteRepository usage
        $getStartTime = $this->testClass->publicProfileStart('get');
        usleep(3000); // Simulate API call
        $this->testClass->publicProfileEnd('get', $getStartTime);

        $postStartTime = $this->testClass->publicProfileStart('post');
        usleep(2000); // Simulate API call
        $this->testClass->publicProfileEnd('post', $postStartTime);

        // Multiple GET calls
        $getStartTime2 = $this->testClass->publicProfileStart('get');
        usleep(1000);
        $this->testClass->publicProfileEnd('get', $getStartTime2);

        Log::shouldReceive('debug')
            ->once()
            ->with('Remote repository timing', \Mockery::on(function ($data) {
                return $data['calls'] === 3 && // Total method calls
                       count($data['calls_breakdown']) === 3 && // All calls tracked
                       $data['total_seconds'] > 0 && // Has timing data
                       isset($data['request_percentage']) && // Percentage calculated
                       collect($data['calls_breakdown'])->where('method', 'get')->count() === 2 && // Two GET calls
                       collect($data['calls_breakdown'])->where('method', 'post')->count() === 1; // One POST call
            }));

        $this->testClass->publicLogTimings();
    }
}
