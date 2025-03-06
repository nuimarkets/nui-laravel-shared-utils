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
        $service = env('APP_NAME', '') . '.' . env('APP_ENV', '');

        Log::info("home info", ['service' => $service]);

        $results = [
            'message' => $service,
        ];

        // Only add debug info if APP_DEBUG is set to true
        if (env('APP_DEBUG') === true || env('APP_DEBUG') === 'true') {
            $results['debug'] = true;
            $results['app_url'] = env('APP_URL');
        }

        return new JsonResponse($results, 200);

    }
}
