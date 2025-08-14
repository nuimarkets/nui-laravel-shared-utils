<?php

namespace NuiMarkets\LaravelSharedUtils\Console\Commands;

use Illuminate\Console\Application;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleRunCommand as BaseScheduleRunCommand;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Run scheduled tasks ensuring Log is used for all stdout
 *
 *   Add to Kernel.php
 *
 *   protected $commands = [
 *     ScheduleRunCommand::class,
 *     WorkCommand::class,
 *   ];
 */
class ScheduleRunCommand extends BaseScheduleRunCommand
{
    /**
     * Run the scheduled tasks.
     *
     * This class ensures that Log is used for all stdout.
     * Supports both Laravel 9 (3 params) and Laravel 10+ (4 params).
     */
    public function handle(
        Schedule $schedule,
        Dispatcher $dispatcher,
        Cache|ExceptionHandler $cacheOrHandler,
        ?ExceptionHandler $handler = null
    ): void {
        if ($handler === null) {
            // Laravel 9: third param is ExceptionHandler
            $this->executeSchedule($schedule, $dispatcher, null, $cacheOrHandler);
        } else {
            // Laravel 10+: third is Cache, fourth is ExceptionHandler
            $this->executeSchedule($schedule, $dispatcher, $cacheOrHandler, $handler);
        }
    }

    /**
     * Internal method to execute scheduled tasks for both Laravel versions.
     */
    private function executeSchedule(Schedule $schedule, Dispatcher $dispatcher, ?Cache $cache, ExceptionHandler $handler): void
    {
        $this->schedule = $schedule;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
        $this->handler = $handler;
        $this->phpBinary = Application::phpBinary();

        $events = $this->schedule->dueEvents($this->laravel);
        $this->eventsRan = false;

        foreach ($events as $event) {
            // Verify if event should actually run.
            if (! $event->filtersPass($this->laravel)) {
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
        if (! $this->eventsRan) {
            Log::debug('No scheduled commands are ready to run.', ['command' => 'scheduler']);
        }
    }

    /**
     * Run the given scheduled event.
     *
     * @param  Event  $event
     */
    protected function runEvent($event): void
    {

        $commandName = $this->resolveCommandName($event);

        Log::withContext(['command' => $commandName]);

        Log::debug('Starting scheduled task');

        $startTime = microtime(true);

        try {
            $event->run($this->laravel);
            $runtime = round((microtime(true) - $startTime) * 1000, 0);
            $this->dispatcher->dispatch(new ScheduledTaskFinished($event, $runtime));
            Log::debug('Finished scheduled task', [

                'duration_ms' => $runtime,
            ]);
        } catch (\Throwable $e) {
            $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            $this->handler->report($e);
            Log::error('Scheduled task failed: ', [
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
     * @param  Event  $event
     */
    protected function runSingleServerEvent($event): void
    {
        if ($this->schedule->serverShouldRun($event, $this->startedAt)) {
            $this->runEvent($event);
        } else {
            $commandName = $this->resolveCommandName($event);
            Log::info('Skipping scheduled task on this server; already executed elsewhere.', ['command' => $commandName]);
            $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));
        }
    }
}
