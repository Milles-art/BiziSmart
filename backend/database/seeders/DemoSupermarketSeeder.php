<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoSupermarketSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Demo seeding is limited to local/testing environments.');
        }
        DB::transaction(function (): void {
            foreach ([
                ['free', 'Free', 0, ['users' => 2, 'products' => 200, 'tills' => 1]],
                ['basic', 'Basic', 70000, ['users' => 5, 'tills' => 2]],
                ['professional', 'Professional', 150000, ['users' => 15, 'tills' => 5]],
                ['enterprise', 'Enterprise', 280000, ['users' => null, 'tills' => null]],
            ] as [$code, $name, $price, $limits]) {
                Plan::firstOrCreate(['code' => $code], ['name' => $name, 'price_monthly' => $price, 'limits' => $limits, 'features' => []]);
            }
            $free = Plan::where('code', 'free')->firstOrFail();
            foreach (['demo-alpha' => 'Demo Alpha Supermarket', 'demo-beta' => 'Demo Beta Mini-Mart'] as $subdomain => $name) {
                $business = Business::firstOrCreate(['subdomain' => $subdomain], [
                    'name' => $name, 'plan_id' => $free->id, 'owner_phone' => '0700000001',
                    'is_active' => true, 'settings' => ['till_variance_limit' => 5000],
                ]);
                app(TenantContext::class)->run($business, function () use ($name, $subdomain): void {
                    app(\App\Services\StaffRoleService::class)->provision();
                    $branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_active' => true]);
                    if (User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->exists()) { return; }
                    $owner = User::forPhone('0700000001')->first();
                    if ($owner === null) {
                        $owner = User::create(['branch_id' => $branch->id, 'name' => $name.' Demo Owner',
                            'phone' => '0700000001', 'pin_hash' => bin2hex(random_bytes(32)),
                            'role_hint' => 'owner', 'is_active' => false, 'must_change_pin' => true]);
                    }
                    app(\App\Services\StaffRoleService::class)->assign($owner, 'owner');
                    Device::firstOrCreate(['device_uid' => $subdomain.'-demo-device'], [
                        'user_id' => $owner->id, 'name' => 'Demo checkout device', 'status' => 'pending',
                    ]);
                });
            }
        });
    }
}
