<?php
namespace App\Services;
use App\Models\{User,Device,PersonalAccessToken};
use Illuminate\Auth\{AuthenticationException,Access\AuthorizationException};

/** Refresh financial authority inside the caller's transaction, before the business lock. */
class FinancialActorService
{
    public function lockSession(User $original): User { return $this->lock($original, null); }

    public function lock(User $original, ?string $permission): User
    {
        $original->assertCurrentBusiness();
        $actor = User::whereKey($original->id)->lockForUpdate()->first();
        $token = $original->currentAccessToken();
        $current = $token ? PersonalAccessToken::whereKey($token->id)->where('tokenable_id',$original->id)->lockForUpdate()->first() : null;
        $device = $current?->device_id ? Device::whereKey($current->device_id)->lockForUpdate()->first() : null;
        if (! $actor || ! $actor->is_active || $actor->must_change_pin || $actor->getRoleNames()->count() !== 1
            || ($actor->hasRole('owner') && ! $actor->ownerAuthenticationReady()) || ! $current || $current->expires_at?->isPast() || $device?->status !== 'approved') { throw new AuthenticationException; }
        if ($permission !== null && ! $actor->can($permission)) { throw new AuthorizationException; }
        return $actor->withAccessToken($current);
    }
}
