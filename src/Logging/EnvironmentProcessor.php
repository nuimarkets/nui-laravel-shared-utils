<?php

namespace Nuimarkets\LaravelSharedUtils\Logging;

use Monolog\Processor\ProcessorInterface;

/**
 * Log Processor for environment info etc
 */
class EnvironmentProcessor implements ProcessorInterface
{
    public function __invoke(array $record): array
    {

        $extraInfo = [
            'environment' => env('APP_ENV', 'testing'),
            'hostname' => gethostname(),
        ];

        if (! empty(env('GIT_COMMIT'))) {
            // Git info
            $extraInfo['git.commit'] = env('GIT_COMMIT');
            $extraInfo['git.branch'] = env('GIT_BRANCH');
            $extraInfo['git.tag'] = env('GIT_TAG');
        }

        // Add command-specific information if it's a console command
        if (app()->runningInConsole()) {
            global $argv;
            $extraInfo['command'] = $argv[1] ?? '';
        } else {

            // Add request-specific information only if in a web request context
            if (app()->bound('request') && request()) {
                $amzTraceId = request()->header('x-amzn-trace-id', '');
                preg_match('/Root=(1-[a-z0-9]+-[a-z0-9]+)/', $amzTraceId, $matches);

                // Get IP from x-forwarded-for header if available, or fall back to request->ip()
                $ip = request()->header('x-forwarded-for') ?: request()->ip();
                // If x-forwarded-for contains multiple IPs, get the first one (client IP)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }

                $extraInfo = array_merge($extraInfo, [
                    'request.amz-trace-id' => $matches[1] ?? '',
                    'request.ip' => $ip ?? '',
                    'request.method' => request()->method() ?? '',
                    'request.url' => request()->url() ?? '',
                    'request.path' => request()->path() ?? '',
                    'request.route' => request()->route()?->uri() ?? '',
                    'request.query' => request()->getQueryString() ?? '',
                    'request.user-agent' => request()->userAgent() ?? '',
                ]);
            }
        }

        $record['extra'] = array_merge($record['extra'], $extraInfo);

        return $record;
    }
}
