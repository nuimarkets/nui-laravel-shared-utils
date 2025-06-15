<?php

namespace Nuimarkets\LaravelSharedUtils\Tests\Unit\Console;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Nuimarkets\LaravelSharedUtils\Console\Commands\TestJob;
use Nuimarkets\LaravelSharedUtils\Tests\TestCase;

class TestJobTest extends TestCase
{
    public function test_command_can_be_created()
    {
        $command = new TestJob;

        $this->assertInstanceOf(TestJob::class, $command);
        $this->assertEquals('test:job', $command->getName());
        $this->assertEquals('Test job', $command->getDescription());
    }

    public function test_command_executes_successfully()
    {
        Queue::fake();
        Log::shouldReceive('info')->once()->with('Dispatching a job');

        $command = new TestJob;

        // Simulate command execution
        $result = $command->handle();

        // Command should complete without throwing exception
        $this->assertNull($result);

        // Verify that a job was dispatched
        Queue::assertPushed(\Nuimarkets\LaravelSharedUtils\Jobs\TestJob::class);
    }

    public function test_command_has_correct_signature_and_description()
    {
        $command = new TestJob;

        // Test that command properties are set correctly
        $this->assertEquals('test:job', $command->getName());
        $this->assertEquals('Test job', $command->getDescription());
    }

    public function test_command_dispatches_job()
    {
        Queue::fake();

        $command = new TestJob;
        $command->handle();

        // Verify the correct job class was dispatched
        Queue::assertPushed(\Nuimarkets\LaravelSharedUtils\Jobs\TestJob::class);
    }
}
