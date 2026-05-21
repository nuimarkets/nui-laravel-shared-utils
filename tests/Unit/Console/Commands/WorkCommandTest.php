<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Console\Commands;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Log;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Console\Commands\WorkCommand;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

class WorkCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_write_output_logs_job_class_and_status_without_exception()
    {
        Log::spy();

        $command = $this->makeWorkCommand();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->once()->andReturn('App\\Jobs\\TestJob');

        $this->invokeWriteOutput($command, $job, 'success');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $this->assertSame('Queue job status update', $message);
                $this->assertSame('App\\Jobs\\TestJob', $context['job_class']);
                $this->assertSame('success', $context['status']);
                $this->assertArrayNotHasKey('exception_class', $context);
                $this->assertArrayNotHasKey('exception_message', $context);

                return true;
            });
    }

    public function test_write_output_includes_exception_details_when_failure_supplies_them()
    {
        Log::spy();

        $exception = new \RuntimeException('Job exploded');

        $command = $this->makeWorkCommand();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->once()->andReturn('App\\Jobs\\TestJob');

        $this->invokeWriteOutput($command, $job, 'failed', $exception);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                $this->assertSame('Queue job status update', $message);
                $this->assertSame('App\\Jobs\\TestJob', $context['job_class']);
                $this->assertSame('failed', $context['status']);
                $this->assertSame(\RuntimeException::class, $context['exception_class']);
                $this->assertSame('Job exploded', $context['exception_message']);

                return true;
            });
    }

    private function makeWorkCommand(): WorkCommand
    {
        $queue = Mockery::mock(QueueManager::class);
        $events = Mockery::mock(Dispatcher::class);
        $exceptions = Mockery::mock(ExceptionHandler::class);
        $cache = Mockery::mock(Cache::class);

        return new WorkCommand($queue, $events, $exceptions, $cache);
    }

    private function invokeWriteOutput(WorkCommand $command, Job $job, string $status, ?\Throwable $exception = null): void
    {
        $method = (new \ReflectionClass($command))->getMethod('writeOutput');
        $method->setAccessible(true);
        $method->invoke($command, $job, $status, $exception);
    }
}
