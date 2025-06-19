<?php

namespace NuiMarkets\LaravelSharedUtils\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Jobs\TestJob as Job;

class TestJob extends Command
{
    protected $signature = 'test:job';

    protected $description = 'Test job';

    public function handle()
    {
        Log::info('Dispatching a job');
        dispatch(new Job);

    }
}
