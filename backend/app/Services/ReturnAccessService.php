<?php
namespace App\Services;
use App\Models\{Branch,Sale,User};
use Illuminate\Auth\Access\AuthorizationException;
final class ReturnAccessService {
    public function receipt(User $actor, Sale $sale): void {
        $actor->assertCurrentBusiness(); $sale->assertCurrentBusiness();
        if (!$actor->can('sell') || (!$actor->hasRole('owner') && (! $actor->hasAnyRole(['manager','cashier']) || $actor->branch_id === null || (int)$actor->branch_id !== (int)$sale->branch_id))) throw new AuthorizationException;
    }
    public function approval(User $actor, ?Branch $branch): bool {
        $actor->assertCurrentBusiness(); $branch?->assertCurrentBusiness();
        return $branch !== null && $actor->can('refund') && ($actor->hasRole('owner') || ($actor->hasRole('manager') && (int)$actor->branch_id === (int)$branch->id));
    }
}
