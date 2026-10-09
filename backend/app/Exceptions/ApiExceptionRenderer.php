<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api', 'api/*')) {
            return null;
        }

        if ($exception instanceof ApiException) {
            return ApiResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->status,
                $exception->field,
                $exception->details,
            );
        }

        if ($exception instanceof ValidationException) {
            return ApiResponse::error('VALIDATION_ERROR', 'Please check the submitted fields.', 422,
                details: ['errors' => $exception->errors()]);
        }

        if ($exception instanceof AuthenticationException) {
            return ApiResponse::error('UNAUTHORIZED', 'Please sign in to continue.', 401);
        }

        if ($exception instanceof AuthorizationException) {
            return ApiResponse::error('PERMISSION_DENIED', 'You do not have permission for this action.', 403);
        }

        if ($exception instanceof ModelNotFoundException) {
            return ApiResponse::error('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        [$code, $message] = match ($status) {
            401 => ['UNAUTHORIZED', 'Please sign in to continue.'],
            403 => ['PERMISSION_DENIED', 'You do not have permission for this action.'],
            404 => ['NOT_FOUND', 'The requested resource was not found.'],
            405 => ['METHOD_NOT_ALLOWED', 'This request method is not supported.'],
            429 => ['RATE_LIMITED', 'Too many requests. Please try again shortly.'],
            503 => ['SERVICE_UNAVAILABLE', 'The service is temporarily unavailable.'],
            default => ['INTERNAL_ERROR', 'Something went wrong. Please try again.'],
        };

        return ApiResponse::error($code, $message, $status, headers: $headers);
    }
}
