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
     * The PHP binary used by the command.
     * Declared here to avoid PHP 8.2 dynamic property deprecation warnings.
     *
     * @var string
     */
    protected $phpBinary;

    /**
     * The cache store implementation.
     * Declared for Laravel 8 compatibility where it might not exist in parent.
     *
     * @var \Illuminate\Contracts\Cache\Repository|null
     */
    protected $cache;

    /**
     * The 24 hour timestamp this scheduler command started running.
     * Re-declared for safety across Laravel versions.
     *
     * @var \Illuminate\Support\Carbon
     */
    protected $startedAt;

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();

        // Initialize startedAt if parent didn't (for Laravel 8 compatibility)
        if (! isset($this->startedAt)) {
            $this->startedAt = now();
        }
    }

    /**
     * Run the scheduled tasks.
     *
     * This class ensures that Log is used for all stdout.
     *
     * Supports both Laravel 8/9 (3 params) and Laravel 10+ (4 params) signatures.
     */
    public function handle(Schedule $schedule, Dispatcher $dispatcher, $cacheOrHandler = null, ?ExceptionHandler $handler = null): void
    {
        // Handle Laravel version differences:
        // Laravel 8/9: handle(Schedule, Dispatcher, ExceptionHandler)
        // Laravel 10+: handle(Schedule, Dispatcher, Cache, ExceptionHandler)
        if ($cacheOrHandler instanceof ExceptionHandler) {
            // Laravel 8/9 signature (3 params)
            $this->handler = $cacheOrHandler;
            $this->cache = null;
        } else {
            // Laravel 10+ signature (4 params)
            $this->cache = $cacheOrHandler;
            $this->handler = $handler;
        }

        $this->schedule = $schedule;
        $this->dispatcher = $dispatcher;
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