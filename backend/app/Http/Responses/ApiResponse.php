<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ApiResponse
{
    public static function success(array $data, int $status = 200, ?array $meta = null): JsonResponse
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return self::respond($payload, $status);
    }

    public static function error(
        string $code,
        string $message,
        int $status,
        ?string $field = null,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];

        if ($field !== null) {
            $error['field'] = $field;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::respond(['success' => false, 'error' => $error], $status, $headers);
    }

    private static function respond(array $payload, int $status, array $headers = []): JsonResponse
    {
        $request = request();
        $requestId = $request->attributes->get('request_id') ?? 'req_'.Str::ulid();
        $request->attributes->set('request_id', $requestId);
        $payload['request_id'] = $requestId;
        if ($request->is('api/v1/auth/*', 'api/v1/me', 'api/v1/me/*', 'api/v1/devices', 'api/v1/devices/*', 'api/v1/users', 'api/v1/users/*', 'api/v1/products', 'api/v1/products/*')) {
            $headers['Cache-Control'] = 'no-store, private';
        }

        return response()->json($payload, $status, [
            ...$headers,
            'X-Request-Id' => $requestId,
        ]);
    }
}
