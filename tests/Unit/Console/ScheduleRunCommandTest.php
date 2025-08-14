<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Console\Commands\ScheduleRunCommand;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class ScheduleRunCommandTest extends TestCase
{
    protected Schedule $schedule;
    protected Dispatcher $dispatcher;
    protected ExceptionHandler $handler;
    protected Cache $cache;
    protected function setUp(): void
    {
        $this->schedule = Mockery::mock(Schedule::class);
        $this->dispatcher = Mockery::mock(Dispatcher::class);
        $this->handler = Mockery::mock(ExceptionHandler::class);
        $this->cache = Mockery::mock(Cache::class);
        $this->schedule->shouldReceive('dueEvents')->once()->andReturn([]);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_with_laravel_9_signature()
    {
        $command = new ScheduleRunCommand;
        $command->setLaravel($this->app);

        // Act - Call with Laravel 9 signature (3 arguments)
        $command->handle($this->schedule, $this->dispatcher, $this->handler);

        // Assert - Verify the properties were set correctly
        $reflection = new \ReflectionClass($command);

        $scheduleProperty = $reflection->getProperty('schedule');
        $this->assertSame($this->schedule, $scheduleProperty->getValue($command));

        $dispatcherProperty = $reflection->getProperty('dispatcher');
        $this->assertSame($this->dispatcher, $dispatcherProperty->getValue($command));

        $handlerProperty = $reflection->getProperty('handler');
        $this->assertSame($this->handler, $handlerProperty->getValue($command));
    }

    public function test_handle_with_laravel_10_signature()
    {
        $command = new ScheduleRunCommand;
        $command->setLaravel($this->app);

        // Act - Call with Laravel 10 signature (4 arguments)
        $command->handle($this->schedule, $this->dispatcher, $this->cache, $this->handler);

        // Assert - Verify the properties were set correctly (cache should be ignored)
        $reflection = new \ReflectionClass($command);

        $scheduleProperty = $reflection->getProperty('schedule');
        $this->assertSame($this->schedule, $scheduleProperty->getValue($command));

        $dispatcherProperty = $reflection->getProperty('dispatcher');
        $this->assertSame($this->dispatcher, $dispatcherProperty->getValue($command));

        $handlerProperty = $reflection->getProperty('handler');
        $this->assertSame($this->handler, $handlerProperty->getValue($command));
    }

    public function test_handle_processes_no_due_events()
    {
        $command = new ScheduleRunCommand;
        $command->setLaravel($this->app);

        // Act
        $command->handle($this->schedule, $this->dispatcher, $this->handler);

        // Assert - eventsRan should be false when no events are due
        $reflection = new \ReflectionClass($command);
        $eventsRanProperty = $reflection->getProperty('eventsRan');
        $this->assertFalse($eventsRanProperty->getValue($command));
    }
}