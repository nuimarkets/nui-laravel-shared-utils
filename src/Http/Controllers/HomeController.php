<?php

namespace NuiMarkets\LaravelSharedUtils\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Home Route (hello/health check)
 */
class HomeController extends Controller
{
    public function home(): JsonResponse
    {
        $results = [
            'status' => 'ok',
            'service' => config('app.name').'.'.config('app.env'),
            'git_tag' => env('GIT_TAG'),
        ];

        // Only add debug info if app.debug is set to true
        if (config('app.debug') === true) {
            $results['debug'] = true;
        }

        return new JsonResponse($results, 200);
    }
}
