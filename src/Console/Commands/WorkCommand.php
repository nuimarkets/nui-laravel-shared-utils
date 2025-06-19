<?php

namespace Nuimarkets\LaravelSharedUtils\Console\Commands;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Console\WorkCommand as BaseWorkCommand;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Log;

/**
 * Run queue work jobs ensuring Log is used for all stdout
 *
 * Add to Kernel.php
 *
 * protected $commands = [
 *   ScheduleRunCommand::class,
 *   WorkCommand::class,
 * ];
 */
class WorkCommand extends BaseWorkCommand
{
    public function __construct(QueueManager $queue, Dispatcher $events, ExceptionHandler $exceptions, Cache $cache)
    {

        $isDownForMaintenance = function () {
            return app()->isDownForMaintenance();
        };

        $worker = new Worker($queue, $events, $exceptions, $isDownForMaintenance);

        parent::__construct($worker, $cache);

    }

    protected function listenForEvents(): void
    {
        $startTimes = [];

        $this->laravel['events']->listen(JobProcessing::class, function ($event) use (&$startTimes) {
            $job = $event->job;
            $jobId = $job->getJobId() ?? $job->uuid() ?? null;
            $jobClass = $job->resolveName();
            $jobName = class_basename($jobClass);
            $startTimes[$jobId] = microtime(true);

            Log::withContext([
                'queue' => config('queue.default'),
                'job' => $jobClass,
                'job_name' => $jobName,
                'job_id' => $jobId,
            ]);

            Log::info('Queue job started');
        });

        $this->laravel['events']->listen(JobProcessed::class, function ($event) use (&$startTimes) {
            $job = $event->job;
            $jobId = $job->getJobId() ?? $job->uuid() ?? null;

            $start = $startTimes[$jobId] ?? microtime(true);
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            unset($startTimes[$jobId]);

            Log::info('Queue job completed', [
                'job_status' => 'DONE',
                'duration_ms' => $durationMs,
            ]);

            Log::withoutContext();
        });

        $this->laravel['events']->listen(JobFailed::class, function ($event) use (&$startTimes) {
            $job = $event->job;
            $jobId = $job->getJobId() ?? $job->uuid() ?? null;

            $start = $startTimes[$jobId] ?? microtime(true);
            $durationMs = (int) ((microtime(true) - $start) * 1000);
            unset($startTimes[$jobId]);

            Log::error('Queue job failed', [
                'job_status' => 'FAILED',
                'duration_ms' => $durationMs,
                'error' => $event->exception->getMessage(),
            ]);

            Log::withoutContext();
        });
    }

    public function handle(): ?int
    {
        $this->listenForEvents();

        Log::withContext(['queue' => $this->option('queue') ?? 'default']);

        Log::info('Processing jobs from queue');

        return $this->runWorker(
            $this->argument('connection'),
            $this->option('queue'),
        );

    }

    protected function writeOutput($job, $status)
    {
        Log::info('Queue job status update', [
            'job_class' => $job->resolveName(),
            'status' => $status,
        ]);
    }
}
