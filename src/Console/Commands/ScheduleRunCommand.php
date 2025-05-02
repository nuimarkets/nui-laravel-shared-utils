<?php

namespace Nuimarkets\LaravelSharedUtils\Console\Commands;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\ScheduleRunCommand as BaseScheduleRunCommand;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;

/**
 * Run scheduled tasks ensuring Log is used for all stdout
 *
 *   Add to Kernel.php
 *
 *   protected $commands = [
 *     ScheduleRunCommand::class,
 *     WorkCommand::class,
 *   ];
 *
 */
class ScheduleRunCommand extends BaseScheduleRunCommand
{
    /**
     * Run the scheduled tasks.
     *
     * This class ensures that Log is used for all stdout.
     *
     * @param Schedule $schedule
     * @param Dispatcher $dispatcher
     * @param ExceptionHandler $handler
     * @return void
     */
    public function handle(Schedule $schedule, Dispatcher $dispatcher, ExceptionHandler $handler): void
    {

        $this->schedule = $schedule;
        $this->dispatcher = $dispatcher;
        $this->handler = $handler;
        $this->phpBinary = Application::phpBinary();

        $events = $this->schedule->dueEvents($this->laravel);
        $this->eventsRan = false;

        foreach ($events as $event) {
            // Verify if event should actually run.
            if (!$event->filtersPass($this->laravel)) {
                $dispatcher->dispatch(new ScheduledTaskSkipped($event));
                continue;
            }
            if ($event->onOneServer) {
                $this->runSingleServerEvent($event);
            } else {
                $this->runEvent($event);
            }
            $this->eventsRan = true;
        }

        // Log a message if there were no due events.
        if (!$this->eventsRan) {
            Log::debug('No scheduled commands are ready to run.', ['command' => 'scheduler']);
        }
    }

    /**
     * Run the given scheduled event.
     *
     * @param Event $event
     * @return void
     */
    protected function runEvent($event): void
    {

        $commandName = $this->resolveCommandName($event);

        Log::withContext(['command' => $commandName]);

        Log::debug("Starting scheduled task");

        $startTime = microtime(true);

        try {
            $event->run($this->laravel);
            $runtime = round((microtime(true) - $startTime) * 1000, 0);
            $this->dispatcher->dispatch(new ScheduledTaskFinished($event, $runtime));
            Log::debug("Finished scheduled task", [

                "duration_ms" => $runtime,
            ]);
        } catch (\Throwable $e) {
            $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            $this->handler->report($e);
            Log::error("Scheduled task failed: ", [
                'error' => $e->getMessage(),


            ]);
        }
    }

    protected function resolveCommandName(Event $event): string
    {
        $summary = $event->getSummaryForDisplay();

        if ($event instanceof CallbackEvent) {
            return $summary;
        }

        // Strip out the PHP binary and the 'artisan' wrapper.
        $name = str_replace(
            [$this->phpBinary, "'artisan' "],
            '',
            $event->command,
        );

        return ltrim($name);
    }

    /**
     * Run a scheduled event that should only run on one server.
     *
     * @param Event $event
     * @return void
     */
    protected function runSingleServerEvent($event): void
    {
        if ($this->schedule->serverShouldRun($event, $this->startedAt)) {
            $this->runEvent($event);
        } else {
            $commandName = $this->resolveCommandName($event);
            Log::info("Skipping scheduled task on this server; already executed elsewhere.", ['command' => $commandName]);
            $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));
        }
    }
}
