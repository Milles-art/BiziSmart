<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Business;
use App\Models\Branch;
use App\Models\Device;
use App\Models\OwnerInvitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Support\StaffPhone;
use Illuminate\Support\Facades\DB;

class OwnerSetupService
{
    public function __construct(private TenantContext $context, private StaffRoleService $roles) {}

    public function invite(): string
    {
        return DB::transaction(function () {
            $this->lockUnconfiguredShop();
            OwnerInvitation::whereNull('consumed_at')->delete();
            $token = bin2hex(random_bytes(32));
            OwnerInvitation::create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(90)]);
            return $token;
        }, 3);
    }

    public function register(#[\SensitiveParameter] array $input): void
    {
        DB::transaction(function () use ($input) {
            $business = $this->lockUnconfiguredShop();
            $invite = OwnerInvitation::where('token_hash', hash('sha256', $input['invitation_token']))->lockForUpdate()->first();
            if (! $invite || $invite->consumed_at || ! $invite->expires_at->isFuture()) {
                throw new ApiException('INVALID_INVITATION', 'This setup link has expired or already been used.', 401);
            }
            // Reuse only the known disabled scaffolding owner; never reset existing operational staff.
            $template = User::where('is_active', false)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
                ->get()->first(fn ($user) => $this->isScaffoldOwner($user, $business));
            if (User::withTrashed()->forPhone($input['phone'])->when($template, fn ($q) => $q->where('id', '!=', $template->id))->exists()) {
                throw new ApiException('VALIDATION_ERROR', 'This phone number is already assigned in this shop.', 422, 'phone');
            }
            $this->roles->provision();
            $owner = $template ?? new User;
            // Platform onboarding already creates Main. Assign only an unambiguous
            // active branch; never guess between branches or replace a scaffold assignment.
            if ($owner->branch_id === null) {
                $branches = Branch::where('is_active', true)->lockForUpdate()->limit(2)->get();
                if ($branches->count() === 1) { $owner->branch_id = $branches->first()->id; }
            }
            $owner->fill(['name' => $input['name'], 'phone' => $input['phone'], 'pin_hash' => $input['pin'],
                'is_active' => true, 'must_change_pin' => false]);
            $owner->save();
            $this->roles->assign($owner, 'owner');
            $business->owner_phone = StaffPhone::normalize($input['phone']);
            $business->save();
            // Unused seeder hardware is not a real registered device.
            Device::where('device_uid', $business->subdomain.'-demo-device')->where('status', 'pending')->whereNull('last_seen_at')->delete();
            $invite->consumed_at = now();
            $invite->save();
            activity('staff')->performedOn($owner)->event('owner_configured')->log('Initial owner configured');
        }, 3);
    }

    private function lockUnconfiguredShop(): Business
    {
        $shop = Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();
        $owners = User::withTrashed()->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->get();
        if ($owners->contains(fn ($owner) => ! $this->isScaffoldOwner($owner, $shop)) || Device::where('status', 'approved')->exists()) {
            throw new ApiException('OWNER_ALREADY_CONFIGURED', 'This shop already has an owner. Sign in to continue.', 409);
        }
        return $shop;
    }

    private function isScaffoldOwner(User $user, Business $shop): bool
    {
        $names = ['demo-alpha' => 'Demo Alpha Supermarket Demo Owner', 'demo-beta' => 'Demo Beta Mini-Mart Demo Owner'];
        return isset($names[$shop->subdomain]) && $user->name === $names[$shop->subdomain]
            && ! $user->is_active && $user->deleted_at === null && $user->must_change_pin
            && $user->phone === '255700000001' && $user->role_hint === 'owner'
            && $user->last_login_at === null && $user->totp_secret === null && $user->totp_confirmed_at === null;
    }
}
