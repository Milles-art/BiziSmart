<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;

/** Resource scope in addition to the till permission and authenticated session checks. */
final class TillAccessService
{
    public function canView(User $actor, Branch $branch, User $cashier): bool
    {
        $actor->assertCurrentBusiness();
        $branch->assertCurrentBusiness();
        $cashier->assertCurrentBusiness();
        if (! $this->staff($actor) || ! $actor->can('manage_till')) { return false; }
        if ($actor->hasRole('owner')) { return true; }
        if ($actor->hasRole('manager')) { return $this->assignedTo($actor, $branch); }
        return $actor->hasRole('cashier') && (int) $actor->id === (int) $cashier->id;
    }

    public function canManage(User $actor, Branch $branch, User $cashier): bool
    {
        // Opening, paid-outs and closing belong to the signed-in cashier, regardless of role.
        return $this->canView($actor, $branch, $cashier)
            && (int) $actor->id === (int) $cashier->id && $this->assignedTo($actor, $branch);
    }

    public function canApproveVariance(User $actor, Branch $branch): bool
    {
        $actor->assertCurrentBusiness();
        $branch->assertCurrentBusiness();
        return $this->staff($actor) && $actor->can('approve_variance')
            && ($actor->hasRole('owner') || ($actor->hasRole('manager') && $this->assignedTo($actor, $branch)));
    }

    private function assignedTo(User $actor, Branch $branch): bool
    {
        return $actor->branch_id !== null && (int) $actor->branch_id === (int) $branch->id;
    }

    private function staff(User $actor): bool
    {
        return $actor->is_active && ! $actor->trashed() && ! $actor->must_change_pin
            && $actor->getRoleNames()->count() === 1;
    }
}
