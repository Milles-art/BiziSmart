<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Support\StaffPhone;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class StaffManagementService
{
    public function __construct(private TenantContext $context, private StaffRoleService $roles) {}

    public function create(User $actor, #[\SensitiveParameter] array $input): User
    {
        return DB::transaction(function () use ($actor, $input) {
            $business = $this->lockOwner($actor);
            $this->checkLimit($business);
            $this->uniquePhone($input['phone']);
            $user = User::create(['name' => $input['name'], 'phone' => $input['phone'], 'pin_hash' => $input['pin'],
                'branch_id' => $this->branch($input['branch_id'] ?? null), 'must_change_pin' => true, 'is_active' => true]);
            $this->roles->assign($user, $input['role']);
            activity('staff')->performedOn($user)->causedBy($actor)->event('staff_created')
                ->withProperties(['role' => $input['role']])->log('Staff created');
            return $user;
        }, 3);
    }

    public function update(User $actor, User $user, #[\SensitiveParameter] array $input): User
    {
        $user->assertCurrentBusiness();
        return DB::transaction(function () use ($actor, $user, $input) {
            $business = $this->lockOwner($actor);
            $user->refreshForUpdate();
            $newRole = $input['role'] ?? $user->getRoleNames()->first();
            $active = $input['is_active'] ?? $user->is_active;
            if ($user->is_active && $user->hasRole('owner') && (! $active || $newRole !== 'owner')
                && User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->count() <= 1) {
                throw new ApiException('LAST_OWNER_REQUIRED', 'Keep at least one active shop owner.', 409);
            }
            if (! $user->is_active && $active) { $this->checkLimit($business); }
            if (isset($input['phone'])) { $this->uniquePhone($input['phone'], $user); }
            $changed = [];
            foreach (['name', 'phone', 'is_active'] as $field) {
                if (array_key_exists($field, $input)) {
                    $value = $field === 'phone' ? StaffPhone::normalize($input[$field]) : $input[$field];
                    if ($user->{$field} !== $value) { $changed[] = $field; }
                    $user->{$field} = $value;
                }
            }
            if (array_key_exists('branch_id', $input)) {
                $branch = $this->branch($input['branch_id']);
                if ($branch !== $user->branch_id) { $changed[] = 'branch_id'; }
                $user->branch_id = $branch;
            }
            if ($newRole !== $user->getRoleNames()->first()) { $changed[] = 'role'; }
            $user->save();
            if (in_array('role', $changed, true)) { $this->roles->assign($user, $newRole); }
            if ($changed !== []) {
                // Reauthenticate after identity, branch, role or activation changes.
                \App\Models\OfflineLease::where('user_id',$user->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]);
                $user->tokens()->delete();
                \App\Models\AuthChallenge::where('user_id', $user->id)->delete();
                \App\Models\SupervisorApproval::revokeFor($user);
                activity('staff')->performedOn($user)->causedBy($actor)->event('staff_updated')
                    ->withProperties(['changed_fields' => $changed])->log('Staff updated');
            }
            return $user->unsetRelation('branch');
        }, 3);
    }

    private function lockOwner(User $actor): Business
    {
        $actor->refreshForUpdate();
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $business = Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        if (! $actor->is_active || ! $actor->hasRole('owner')) { throw new \Illuminate\Auth\Access\AuthorizationException; }
        return $business;
    }

    private function checkLimit(Business $business): void
    {
        $limit = $business->plan->limits['users'] ?? null;
        if ($limit !== null && User::where('is_active', true)->count() >= $limit) {
            throw new ApiException('PLAN_LIMIT_REACHED', 'Your plan’s active staff limit has been reached.', 403);
        }
    }

    private function uniquePhone(string $phone, ?User $except = null): void
    {
        if (User::withTrashed()->forPhone($phone)->when($except, fn ($q) => $q->where('id', '!=', $except->id))->exists()) {
            throw new ApiException('VALIDATION_ERROR', 'This phone number is already assigned in this shop.', 422, 'phone');
        }
    }

    private function branch(?string $publicId): ?int
    {
        if ($publicId === null) { return null; }
        $branch = Branch::where('public_id', substr($publicId, 3))->where('is_active', true)->first();
        if (! $branch) { throw new ApiException('VALIDATION_ERROR', 'Choose an active branch in this shop.', 422, 'branch_id'); }
        return $branch->id;
    }
}
