<?php

namespace Nuimarkets\LaravelSharedUtils\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Nuimarkets\LaravelSharedUtils\Jobs\TestFailedJob as Job;

class TestFailedJob extends Command
{
    protected $signature = 'test:failed-job';

    protected $description = 'Test failed job';

    public function handle()
    {
        Log::info('Dispatching a job that will fail...');
        dispatch(new Job);

    }
}
