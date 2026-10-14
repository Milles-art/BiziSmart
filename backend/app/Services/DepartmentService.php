<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\Department;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DepartmentService
{
    public function __construct(private TenantContext $context) {}
    public function save(User $actor, array $input, ?Department $department = null): Department
    {
        $department?->assertCurrentBusiness();
        return DB::transaction(function () use ($actor, $input, $department) {
            $this->lockShop($actor);
            if ($department) { $this->lockDepartment($department); }
            $duplicate = Department::where('current_name', $input['name']);
            if ($department) { $duplicate->whereKeyNot($department->id); }
            if ($duplicate->exists()) { throw new ApiException('DUPLICATE_DEPARTMENT', 'A department with this name already exists in this shop.', 409, 'name'); }
            $before = $department ? ['name' => $department->name] : null;
            $department ??= new Department;
            $department->name = $input['name']; $department->current_name = $input['name']; $department->save();
            $after = ['name' => $department->name];
            if ($before !== $after) {
                activity('products')->performedOn($department)->causedBy($actor)->event($before ? 'department_updated' : 'department_created')
                    ->withProperties(['old' => $before, 'attributes' => $after])->log('Department catalogue changed');
            }
            return $department->loadCount('assignedProducts');
        }, 3);
    }
    public function archive(User $actor, Department $department): void
    {
        $department->assertCurrentBusiness();
        DB::transaction(function () use ($actor, $department) {
            $this->lockShop($actor); $this->lockDepartment($department);
            if ($department->assignedProducts()->exists()) { throw new ApiException('DEPARTMENT_IN_USE', 'Reassign or clear products before archiving this department. Inactive and archived products also retain their assignment.', 409); }
            $before = ['name' => $department->name];
            $department->current_name = null; $department->save(); $department->delete();
            activity('products')->performedOn($department)->causedBy($actor)->event('department_archived')
                ->withProperties(['old' => $before, 'attributes' => ['archived' => true]])->log('Department archived');
        }, 3);
    }
    private function lockShop(User $actor): void
    {
        $actor->refreshForUpdate(); $actor->unsetRelation('roles')->unsetRelation('permissions');
        Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        if (! $actor->is_active || ! $actor->can('manage_products')) { throw new AuthorizationException; }
    }
    private function lockDepartment(Department $department): void
    {
        $department->refreshForUpdate();
        if ($department->trashed()) { throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404); }
    }
}
