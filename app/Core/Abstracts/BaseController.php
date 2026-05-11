<?php

namespace App\Core\Abstracts;

use App\Core\Traits\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response as ResponseAlias;

abstract class BaseController extends Controller
{
    use ApiResponse;

    protected function responseWithResource($resource, $message, $code = ResponseAlias::HTTP_OK): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($resource, $message, $code);
    }

    protected function respondWithCollection($collection, $message = '', $code = ResponseAlias::HTTP_OK): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($collection, $message, $code);
    }

    protected function respondError($message, $code = ResponseAlias::HTTP_BAD_REQUEST): \Illuminate\Http\JsonResponse
    {
        return $this->errorResponse($message, $code);
    }

    protected function respondNotFound($message = 'Resource not found'): \Illuminate\Http\JsonResponse
    {
        return $this->errorResponse($message, ResponseAlias::HTTP_NOT_FOUND);
    }

    protected function respondUnauthorized($message = 'Unauthorized'): \Illuminate\Http\JsonResponse
    {
        return $this->errorResponse($message, ResponseAlias::HTTP_UNAUTHORIZED);
    }

    protected function respondForbidden($message = 'Forbidden'): \Illuminate\Http\JsonResponse
    {
        return $this->errorResponse($message, ResponseAlias::HTTP_FORBIDDEN);
    }

    protected function respondValidationErrors($errors): \Illuminate\Http\JsonResponse
    {
        return $this->errorResponse(
            'Validation errors',
            ResponseAlias::HTTP_UNPROCESSABLE_ENTITY,
            $errors
        );
    }
}
