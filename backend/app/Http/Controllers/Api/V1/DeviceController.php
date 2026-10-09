<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController
{
    public function __construct(private DeviceService $devices) {}
    public function index(): JsonResponse
    {
        return ApiResponse::success(['devices' => Device::with(['user', 'approver'])->orderBy('created_at')->get()->map(fn ($device) => [
            'id' => $device->publicId(), 'name' => $device->name, 'status' => $device->status,
            'user_id' => $device->user?->publicId(), 'approved_by' => $device->approver?->publicId(), 'last_seen_at' => $device->last_seen_at?->toISOString(),
        ])->all()]);
    }
    public function approve(Request $request, Device $device): JsonResponse
    {
        $this->devices->setStatus($request->user(), $device, 'approved');
        return ApiResponse::success(['completed' => true]);
    }
    public function block(Request $request, Device $device): JsonResponse
    {
        $this->devices->setStatus($request->user(), $device, 'blocked');
        return ApiResponse::success(['completed' => true]);
    }
}
