<?php

namespace NuiMarkets\LaravelSharedUtils\Jobs;

use Illuminate\Queue\Jobs\Job;

class TestFailedJob extends Job
{
    public function handle()
    {
        // This will cause the job to fail
        throw new \Exception('This is a test exception');
    }

    public function getJobId()
    {
        return 1;
    }

    public function getRawBody()
    {
        return '';
    }
}
