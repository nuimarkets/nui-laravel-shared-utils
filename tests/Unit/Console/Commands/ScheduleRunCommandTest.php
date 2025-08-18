<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Console\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Console\Commands\ScheduleRunCommand;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class ScheduleRunCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock Log facade
        Log::shouldReceive('withContext')->andReturnSelf();
        Log::shouldReceive('debug')->andReturnTrue();
        Log::shouldReceive('error')->andReturnTrue();
        Log::shouldReceive('info')->andReturnTrue();
    }

    /**
     * Test handle method with Laravel 8/9 signature (3 parameters).
     */
    public function test_handle_with_laravel_8_9_signature()
    {
        // Arrange
        $schedule = Mockery::mock(Schedule::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $handler = Mockery::mock(ExceptionHandler::class);
        
        $schedule->shouldReceive('dueEvents')
            ->once()
            ->andReturn(collect([]));
        
        $command = new ScheduleRunCommand();
        $command->setLaravel($this->app);

        // Act - Laravel 8/9 signature: handle(Schedule, Dispatcher, ExceptionHandler)
        $command->handle($schedule, $dispatcher, $handler);

        // Assert - Should have properly detected Laravel 8/9 and handled the parameters
        $this->assertTrue(true); // Test passes if no exceptions thrown
    }

    /**
     * Test handle method with Laravel 10+ signature (4 parameters).
     */
    public function test_handle_with_laravel_10_signature()
    {
        // Arrange
        $schedule = Mockery::mock(Schedule::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $cache = Mockery::mock(Cache::class);
        $handler = Mockery::mock(ExceptionHandler::class);
        
        $schedule->shouldReceive('dueEvents')
            ->once()
            ->andReturn(collect([]));
        
        $command = new ScheduleRunCommand();
        $command->setLaravel($this->app);

        // Act - Laravel 10+ signature: handle(Schedule, Dispatcher, Cache, ExceptionHandler)
        $command->handle($schedule, $dispatcher, $cache, $handler);

        // Assert - Should have properly detected Laravel 10+ and handled the parameters
        $this->assertTrue(true); // Test passes if no exceptions thrown
    }

    /**
     * Test that the command properly handles events when there are no due events.
     */
    public function test_logs_when_no_events_are_due()
    {
        // Arrange
        $schedule = Mockery::mock(Schedule::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $cache = Mockery::mock(Cache::class);
        $handler = Mockery::mock(ExceptionHandler::class);
        
        $schedule->shouldReceive('dueEvents')
            ->once()
            ->andReturn(collect([]));
        
        $command = new ScheduleRunCommand();
        $command->setLaravel($this->app);

        // Act
        $command->handle($schedule, $dispatcher, $cache, $handler);

        // Assert - Should log that no commands are ready
        Log::shouldHaveReceived('debug')
            ->with('No scheduled commands are ready to run.', ['command' => 'scheduler']);
        
        // Add explicit assertion for the test
        $this->assertTrue(true);
    }

    /**
     * Test that both signature styles work without throwing exceptions.
     * This is the main compatibility test.
     */
    public function test_both_signatures_are_compatible()
    {
        $schedule = Mockery::mock(Schedule::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $cache = Mockery::mock(Cache::class);
        $handler = Mockery::mock(ExceptionHandler::class);
        
        $schedule->shouldReceive('dueEvents')
            ->andReturn(collect([]));
        
        $command = new ScheduleRunCommand();
        $command->setLaravel($this->app);

        // Test Laravel 8/9 signature (3 params)
        try {
            $command->handle($schedule, $dispatcher, $handler);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('Laravel 8/9 signature should work: ' . $e->getMessage());
        }

        // Test Laravel 10 signature (4 params)
        try {
            $command->handle($schedule, $dispatcher, $cache, $handler);
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('Laravel 10 signature should work: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}