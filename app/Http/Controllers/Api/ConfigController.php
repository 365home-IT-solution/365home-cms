<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'google_maps_api_key' => config('services.google_maps.key'),
        ]);
    }

    public function map(): JsonResponse
    {
        return response()->json([
            'google_maps_api_key' => config('services.google_maps.key'),
        ]);
    }
}
