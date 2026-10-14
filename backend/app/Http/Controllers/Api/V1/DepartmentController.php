<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Products\SaveDepartmentRequest;
use App\Http\Requests\Products\ListDepartmentsRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Department;
use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController
{
    public function __construct(private DepartmentService $departments) {}
    public function index(ListDepartmentsRequest $request): JsonResponse
    {
        $input = $request->validated();
        $query = Department::query();
        if ($request->user()->can('manage_products')) { $query->withCount('assignedProducts'); }
        if (isset($input['search'])) { $query->where('name', 'like', '%'.addcslashes($input['search'], '\\%_').'%'); }
        $rows = $query->orderBy('name')->orderBy('id')->paginate($input['per_page'] ?? 50);
        return ApiResponse::success(['departments' => DepartmentResource::collection($rows->items())->resolve()], meta: [
            'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()]);
    }
    public function store(SaveDepartmentRequest $request): JsonResponse
    {
        return ApiResponse::success((new DepartmentResource($this->departments->save($request->user(), $request->validated())))->resolve(), 201);
    }
    public function update(SaveDepartmentRequest $request, Department $department): JsonResponse
    {
        return ApiResponse::success((new DepartmentResource($this->departments->save($request->user(), $request->validated(), $department)))->resolve());
    }
    public function destroy(Request $request, Department $department): JsonResponse
    {
        $this->departments->archive($request->user(), $department);
        return ApiResponse::success(['completed' => true]);
    }
}
