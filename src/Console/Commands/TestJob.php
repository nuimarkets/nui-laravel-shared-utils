<?php

namespace Nuimarkets\LaravelSharedUtils\Console\Commands;

use Nuimarkets\LaravelSharedUtils\Jobs\TestJob as Job;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestJob extends Command
{
    protected $signature = 'test:job';
    protected $description = 'Test job';

    public function handle()
    {
        Log::info('Dispatching a job');
        dispatch(new Job());

    }
}
