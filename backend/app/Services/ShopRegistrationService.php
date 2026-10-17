<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Plan;
use App\Models\User;
use App\Support\StaffPhone;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Public shop registration (D115). The tenant always comes from the shop host
 * that reached the application, never from request input, so a signup can only
 * ever create the shop addressed by the browser. Staff remain owner-created.
 */
class ShopRegistrationService
{
    public function __construct(private TenantContext $context, private StaffRoleService $roles) {}

    /** Host-validated shop name; an unknown or reserved host is simply not found. */
    public function subdomainFor(string $host): string
    {
        $host = strtolower($host);
        $suffix = '.'.strtolower((string) config('tenancy.domain'));
        if (! str_ends_with($host, $suffix)) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }
        $subdomain = substr($host, 0, -strlen($suffix));
        if (! $this->validSubdomain($subdomain)) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $subdomain;
    }

    /** A shop address may be claimed only while it has never existed, including while deactivated. */
    public function available(string $subdomain): bool
    {
        return $this->validSubdomain($subdomain)
            && ! Business::query()->where('subdomain', $subdomain)->exists();
    }

    /**
     * Create the shop, its Main branch, the approved owner role scaffold and the
     * first owner in one transaction. Email credentials and the first session are
     * assigned by the caller inside this tenant.
     */
    public function create(#[\SensitiveParameter] array $input, string $subdomain): Business
    {
        if (! $this->available($subdomain)) {
            throw new ApiException('SHOP_ALREADY_EXISTS', 'This shop address is already taken. Sign in instead.', 409);
        }
        $phone = StaffPhone::normalize($input['phone']);
        // New shops start on the cheapest configured plan; platform onboarding owns upgrades.
        $plan = Plan::orderBy('price_monthly')->value('id');
        if ($plan === null) {
            throw new ApiException('REGISTRATION_UNAVAILABLE', 'Shop registration is not available right now. Contact BiziSmart.', 503);
        }

        return DB::transaction(function () use ($input, $subdomain, $phone, $plan) {
            // A missing row cannot be locked; the unique index on subdomain is the authority.
            $business = Business::create([
                'name' => trim($input['shop_name']), 'subdomain' => $subdomain,
                'plan_id' => $plan, 'owner_phone' => $phone,
                'is_active' => true, 'settings' => ['till_variance_limit' => 5000],
            ]);
            $this->context->set($business);
            $this->roles->provision();
            $branch = Branch::create(['name' => 'Main Branch', 'is_active' => true]);
            // The generated unknown PIN is never returned and cannot sign in.
            $owner = User::create(['branch_id' => $branch->id, 'name' => trim($input['name']), 'phone' => $phone,
                'pin_hash' => bin2hex(random_bytes(32)), 'role_hint' => 'owner', 'is_active' => true, 'must_change_pin' => false]);
            $this->roles->assign($owner, 'owner');
            activity('staff')->performedOn($owner)->event('shop_registered')->log('Shop owner registered');

            return $business;
        }, 3);
    }

    private function validSubdomain(string $subdomain): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain)
            && ! in_array($subdomain, config('tenancy.reserved_subdomains'), true);
    }
}