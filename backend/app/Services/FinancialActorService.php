<?php
namespace App\Services;
use App\Models\{User,Device,PersonalAccessToken};
use Illuminate\Auth\{AuthenticationException,Access\AuthorizationException};

/**
 * Single authority for authenticated-session revalidation.
 *
 * - validate(): non-locking predicate for HTTP middleware.
 * - lock()/lockWithDevice(): the same predicate while locking user/token/device
 *   rows inside the caller's transaction, before any business/product locks.
 *
 * Every in-transaction caller must go through this class so an auth-rule change
 * (D109 enrolment, device approval, role shape) is applied in exactly one place.
 * Login/challenge flows in StaffAuthService are intentionally excluded: no bearer
 * token exists yet when credentials are being verified.
 */
class FinancialActorService
{
    /** Non-locking session check for request middleware (no transaction required). */
    public function validate(?User $actor, ?string $permission = null): void
    {
        $token = $actor?->currentAccessToken();
        $device = $token?->device_id ? Device::whereKey($token->device_id)->first() : null;
        $this->assertSession($actor, $token, $device, $permission);
    }

    /** Locks the actor's user/token/device rows and returns the actor with its current token. */
    public function lock(User $original, ?string $permission): User
    {
        return $this->lockWithDevice($original, $permission)[0];
    }

    /**
     * Same as lock() but also returns the approved device row for movement/journal
     * attribution, so callers never re-query it after validation.
     *
     * @return array{0: User, 1: Device}
     */
    public function lockWithDevice(User $original, ?string $permission): array
    {
        $original->assertCurrentBusiness();
        $actor = User::whereKey($original->id)->lockForUpdate()->first();
        $token = $original->currentAccessToken();
        $current = $token ? PersonalAccessToken::whereKey($token->id)->where('tokenable_id', $original->id)->lockForUpdate()->first() : null;
        $device = $current?->device_id ? Device::whereKey($current->device_id)->lockForUpdate()->first() : null;
        $this->assertSession($actor, $current, $device, $permission);
        return [$actor->withAccessToken($current), $device];
    }

    private function assertSession(?User $actor, ?PersonalAccessToken $token, ?Device $device, ?string $permission): void
    {
        if (! $actor || ! $actor->is_active || $actor->must_change_pin || $actor->getRoleNames()->count() !== 1
            || ($actor->hasRole('owner') && ! $actor->ownerAuthenticationReady())
            || ! $token || $token->expires_at?->isPast() || $device?->status !== 'approved') {
            throw new AuthenticationException;
        }
        if ($permission !== null && ! $actor->can($permission)) {
            throw new AuthorizationException;
        }
    }
}
