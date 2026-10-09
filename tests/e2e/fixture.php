<?php

use App\Models\Business;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Services\StaffRoleService;
use App\Services\OwnerSetupService;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->loadEnvironmentFrom(is_file(__DIR__.'/../../backend/.env.testing') ? '.env.testing' : '.env');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new LogicException('Browser fixtures require a dedicated MySQL test database.');
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Browser test migrations failed.');
}
DB::transaction(function () {
    $plan = Plan::firstOrCreate(['code' => 'e2e'], ['name' => 'Browser test', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    $business = Business::firstOrCreate(['subdomain' => 'e2e-shop'], [
        'name' => 'Browser Test Supermarket', 'plan_id' => $plan->id, 'owner_phone' => '0700000099', 'is_active' => true,
    ]);
    app(TenantContext::class)->run($business, function () {
        app(StaffRoleService::class)->provision();
        foreach (['desktop' => '0711111101', 'mobile' => '0711111102'] as $label => $phone) {
            $user = User::forPhone($phone)->first() ?? new User;
            // Synthetic credentials exist only in the dedicated test database.
            $user->fill(['name' => 'Browser cashier', 'phone' => $phone, 'pin_hash' => '2468',
                'must_change_pin' => false, 'is_active' => true]);
            $user->failed_pin_attempts = 0;
            $user->locked_until = null;
            $user->save();
            app(StaffRoleService::class)->assign($user, 'cashier');
            $user->tokens()->delete();
            Device::updateOrCreate(['device_uid' => 'e2e-'.$label.'-approved'], [
                'user_id' => $user->id, 'name' => 'Browser test till', 'status' => 'approved',
            ]);
        }
        foreach (['0711111103', '0711111104'] as $phone) {
            $manager = User::forPhone($phone)->first() ?? new User;
            $manager->fill(['name' => 'Browser approval manager', 'phone' => $phone, 'pin_hash' => '3579',
                'must_change_pin' => false, 'is_active' => true]);
            $manager->failed_pin_attempts = 0; $manager->locked_until = null; $manager->save();
            app(StaffRoleService::class)->assign($manager, 'manager');
        }
    });
});

$invitations = [];
foreach (['desktop', 'mobile'] as $label) {
    $slug = 'e2e-'.$label.'-'.bin2hex(random_bytes(3));
    $business = Business::create(['name' => 'Fresh Choice Browser Test', 'subdomain' => $slug,
        'plan_id' => Plan::where('code', 'e2e')->firstOrFail()->id, 'owner_phone' => '0700000099']);
    $token = app(TenantContext::class)->run($business, fn () => app(OwnerSetupService::class)->invite());
    $invitations[$label] = ['url' => 'http://'.$slug.'.localhost:15173/#setup='.$token];
}
if (! is_dir(__DIR__.'/../../tmp')) mkdir(__DIR__.'/../../tmp', 0700, true);
file_put_contents(__DIR__.'/../../tmp/e2e-invitations.json', json_encode($invitations));
