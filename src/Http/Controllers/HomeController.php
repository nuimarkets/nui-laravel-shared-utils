<?php

namespace Nuimarkets\LaravelSharedUtils\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Home Route (hello/health check)
 */
class HomeController extends Controller
{
    public function home(): JsonResponse
    {
        $service = config('app.name') . '.' . config('app.env');

        Log::info("home info", ['service' => $service]);

        $results = [
            'message' => $service,
        ];

        // Only add debug info if app.debug is set to true
        if (config('app.debug') === true) {
            $results['debug'] = true;
            $results['app_url'] = env('APP_URL');
        }

        return new JsonResponse($results, 200);

    }
}
