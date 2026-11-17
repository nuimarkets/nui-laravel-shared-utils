<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs tests/WithoutCurl in a child PHP process where ext-curl stays loaded but
 * curl_exec and curl_multi_exec are disabled. Neither can be switched off from
 * inside a running process, so this is the only way to reach that platform.
 */
class WithoutCurlTransportTest extends TestCase
{
    private const EXPECTED_TESTS = 6;

    public function test_budgeted_calls_fail_safely_when_curl_cannot_run()
    {
        $root = dirname(__DIR__, 2);
        $junit = tempnam(sys_get_temp_dir(), 'without-curl-');

        try {
            $process = new Process([
                PHP_BINARY,
                '-d', 'disable_functions=curl_exec,curl_multi_exec',
                $root.'/vendor/bin/phpunit',
                '--do-not-cache-result',
                '--colors=never',
                '--log-junit', $junit,
                $root.'/tests/WithoutCurl',
            ], $root);
            $process->setTimeout(120);
            $process->run();

            $output = $process->getOutput().$process->getErrorOutput();
            $this->assertSame(0, $process->getExitCode(), $output);

            $suite = simplexml_load_file($junit)->testsuite;
            $this->assertSame(self::EXPECTED_TESTS, (int) $suite['tests'], $output);
            $this->assertSame(0, (int) $suite['skipped'], $output);
            $this->assertSame(0, (int) $suite['failures'] + (int) $suite['errors'], $output);
        } finally {
            @unlink($junit);
        }
    }
}
