<?php

namespace App\Core\Traits;
use Illuminate\Http\JsonResponse;
trait ApiResponse
{

    protected function successResponse($data, $message = null, $code = 200): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $code);
    }

    protected function errorResponse($message, $code): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'data' => null
        ], $code);
    }

}
