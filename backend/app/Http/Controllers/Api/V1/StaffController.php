<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Staff\SaveStaffRequest;
use App\Http\Resources\ManagedStaffResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\StaffManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController
{
    public function __construct(private StaffManagementService $staff) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $users = User::with(['roles', 'branch'])->orderBy('id')->paginate($input['per_page'] ?? 50);
        return ApiResponse::success(['users' => ManagedStaffResource::collection($users->items())->resolve()], meta: [
            'page' => $users->currentPage(), 'per_page' => $users->perPage(), 'total' => $users->total(),
        ]);
    }

    public function store(SaveStaffRequest $request): JsonResponse
    {
        return ApiResponse::success((new ManagedStaffResource($this->staff->create($request->user(), $request->validated())))->resolve(), 201);
    }

    public function update(SaveStaffRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success((new ManagedStaffResource($this->staff->update($request->user(), $user, $request->validated())))->resolve());
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->staff->update($request->user(), $user, ['is_active' => false]);
        return ApiResponse::success(['completed' => true]);
    }
}
