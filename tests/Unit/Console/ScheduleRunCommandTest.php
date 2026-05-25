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

    public function test_handle_with_l10_l11_signature()
    {
        $command = new ScheduleRunCommand;
        $command->setLaravel($this->app);

        $command->handle($this->schedule, $this->dispatcher, $this->cache, $this->handler);

        $reflection = new \ReflectionClass($command);

        $scheduleProperty = $reflection->getProperty('schedule');
        $this->assertSame($this->schedule, $scheduleProperty->getValue($command));

        $dispatcherProperty = $reflection->getProperty('dispatcher');
        $this->assertSame($this->dispatcher, $dispatcherProperty->getValue($command));

        $handlerProperty = $reflection->getProperty('handler');
        $this->assertSame($this->handler, $handlerProperty->getValue($command));

        $cacheProperty = $reflection->getProperty('cache');
        $this->assertSame($this->cache, $cacheProperty->getValue($command));
    }

    public function test_handle_processes_no_due_events()
    {
        $command = new ScheduleRunCommand;
        $command->setLaravel($this->app);

        $command->handle($this->schedule, $this->dispatcher, $this->cache, $this->handler);

        $reflection = new \ReflectionClass($command);
        $eventsRanProperty = $reflection->getProperty('eventsRan');
        $this->assertFalse($eventsRanProperty->getValue($command));
    }
}
