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
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../backend/vendor/autoload.php';
$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->loadEnvironmentFrom(is_file(__DIR__.'/../../backend/.env.testing') ? '.env.testing' : '.env');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql'
    || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new LogicException('Browser fixtures require a dedicated MySQL test database.');
}
\Tests\Support\GuardedTestDatabase::assertConnection();
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Browser test migrations failed.');
}
// Start each browser run with committed empty test fixtures, retaining the migrated schema.
// The guard above is mandatory: no development or production database can reach this cleanup.
\Tests\Support\GuardedTestDatabase::assertConnection();
DB::statement('SET FOREIGN_KEY_CHECKS=0');
try {
    DB::transaction(function () {
        foreach (Schema::getTables() as $table) {
            $name=$table['name'];
            if ($name==='migrations') continue;
            if (! preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new LogicException('Unexpected browser fixture table name.');
            DB::statement('DELETE FROM `'.$name.'`'.(Schema::hasColumn($name,'business_id')?' WHERE business_id >= 0 OR business_id IS NULL':''));
        }
    });
} finally {
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}
DB::transaction(function () {
    $plan = Plan::firstOrCreate(['code' => 'e2e'], ['name' => 'Browser test', 'price_monthly' => 0, 'limits' => ['tills' => 2], 'features' => []]);
    $plan->update(['limits' => ['tills' => 2]]);
    $business = Business::firstOrCreate(['subdomain' => 'e2e-shop'], [
        'name' => 'Browser Test Supermarket', 'plan_id' => $plan->id, 'owner_phone' => '0700000099', 'is_active' => true,
    ]);
    app(TenantContext::class)->run($business, function () {
        app(StaffRoleService::class)->provision();
        $branch = \App\Models\Branch::firstOrCreate(['name' => 'Main'], ['is_active' => true]);
        $keeper = User::forPhone('0711111105')->first() ?? new User;
        $keeper->fill(['name'=>'Browser stockkeeper','phone'=>'0711111105','pin_hash'=>'1234','must_change_pin'=>false,'is_active'=>true,'branch_id'=>$branch->id]); $keeper->save();
        app(StaffRoleService::class)->assign($keeper,'stockkeeper');
        foreach (['desktop','mobile'] as $label) {
            $product = \App\Models\Product::firstOrCreate(['name'=>'Checkout water '.$label], ['quantity_type'=>'counted','unit'=>'bottle',
                'selling_price'=>8500,'buying_price'=>100,'reorder_level_atoms'=>0,'tracks_expiry'=>false,'is_active'=>true]);
            app(\App\Services\StockService::class)->receive($keeper,$product,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),
                'batch_number'=>'BROWSER-CASH','qty'=>'3','cost_price'=>100,'expiry_date'=>null]);
        }
        foreach(['desktop','mobile'] as $label){
            $product=\App\Models\Product::firstOrCreate(['name'=>'POS tools water '.$label],['quantity_type'=>'counted','unit'=>'bottle','selling_price'=>8500,'buying_price'=>100,'reorder_level_atoms'=>0,'tracks_expiry'=>false,'is_active'=>true,'barcode'=>'TOOLS-'.strtoupper($label)]);
            \App\Models\ProductBarcode::firstOrCreate(['barcode'=>$product->barcode],['product_id'=>$product->id]);
            app(\App\Services\StockService::class)->receive($keeper,$product,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>'BROWSER-TOOLS','qty'=>'10','cost_price'=>100,'expiry_date'=>null]);
        }
        foreach (['desktop' => '0711111101', 'mobile' => '0711111102'] as $label => $phone) {
            $user = User::forPhone($phone)->first() ?? new User;
            // Synthetic credentials exist only in the dedicated test database.
            $user->fill(['branch_id' => $branch->id, 'name' => 'Browser cashier', 'phone' => $phone, 'pin_hash' => '2468',
                'must_change_pin' => false, 'is_active' => true]);
            $user->failed_pin_attempts = 0;
            $user->locked_until = null;
            $user->save();
            app(StaffRoleService::class)->assign($user, 'cashier');
            $user->tokens()->delete();
            $device = Device::updateOrCreate(['device_uid' => 'e2e-'.$label.'-approved'], [
                'user_id' => $user->id, 'name' => 'Browser test till', 'status' => 'approved',
            ]);
            // Close interrupted synthetic shifts through the normal service; never reset a development till.
            foreach (\App\Models\TillSession::where('cashier_id', $user->id)->where('status', 'open')->get() as $till) {
                $session = $user->createToken('fixture-cleanup', ['*'], now()->addMinutes(5));
                $session->accessToken->device_id = $device->id; $session->accessToken->save();
                $user->withAccessToken($session->accessToken);
                $service = app(\App\Services\TillService::class);
                $review = $service->preview($user, ['operation_id' => (string) \Illuminate\Support\Str::uuid(),
                    'till_id' => $till->publicId(), 'counted_cash' => $service->view($till)['expected_cash']]);
                $service->close($user, array_diff_key($review['snapshot'], ['branch_id' => true]));
                $session->accessToken->delete();
            }
        }
        foreach (['0711111103', '0711111104'] as $phone) {
            $manager = User::forPhone($phone)->first() ?? new User;
            $manager->fill(['branch_id' => $branch->id, 'name' => 'Browser approval manager', 'phone' => $phone, 'pin_hash' => '3579',
                'must_change_pin' => false, 'is_active' => true]);
            $manager->failed_pin_attempts = 0; $manager->locked_until = null; $manager->save();
            app(StaffRoleService::class)->assign($manager, 'manager');
        }
    });
});

// Separate domains prevent one interrupted browser workflow from occupying another suite's till.
foreach (['e2e-pos-tools'=>'tools','e2e-selcom-ui'=>'selcom'] as $subdomain=>$workflow) {
    DB::transaction(function () use ($subdomain,$workflow) {
        $business=Business::create(['name'=>'Browser Test Supermarket','subdomain'=>$subdomain,
            'plan_id'=>Plan::where('code','e2e')->firstOrFail()->id,'owner_phone'=>'0700000099','is_active'=>true]);
        app(TenantContext::class)->run($business,function () use ($workflow) {
            app(StaffRoleService::class)->provision();
            $branch=\App\Models\Branch::create(['name'=>'Main','is_active'=>true]);
            $keeper=User::create(['name'=>'Browser stockkeeper','phone'=>'0711111105','pin_hash'=>'1234',
                'must_change_pin'=>false,'is_active'=>true,'branch_id'=>$branch->id]);
            app(StaffRoleService::class)->assign($keeper,'stockkeeper');
            foreach (['desktop'=>'0711111101','mobile'=>'0711111102'] as $label=>$phone) {
                $cashier=User::create(['name'=>'Browser cashier','phone'=>$phone,'pin_hash'=>'2468',
                    'must_change_pin'=>false,'is_active'=>true,'branch_id'=>$branch->id]);
                app(StaffRoleService::class)->assign($cashier,'cashier');
                Device::create(['device_uid'=>'e2e-'.$label.'-approved','user_id'=>$cashier->id,'name'=>'Browser test till','status'=>'approved']);
                $attributes=['name'=>($workflow==='tools'?'POS tools water ':'Checkout water ').$label,
                    'quantity_type'=>'counted','unit'=>'bottle','selling_price'=>8500,'buying_price'=>100,
                    'reorder_level_atoms'=>0,'tracks_expiry'=>false,'is_active'=>true];
                if ($workflow==='tools') $attributes['barcode']='TOOLS-'.strtoupper($label);
                $product=\App\Models\Product::create($attributes);
                if ($workflow==='tools') \App\Models\ProductBarcode::create(['barcode'=>$product->barcode,'product_id'=>$product->id]);
                app(\App\Services\StockService::class)->receive($keeper,$product,[
                    'operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>$workflow==='tools'?'BROWSER-TOOLS':'BROWSER-CASH',
                    'qty'=>$workflow==='tools'?'10':'3','cost_price'=>100,'expiry_date'=>null]);
            }
            foreach (['0711111103','0711111104'] as $phone) {
                $manager=User::create(['name'=>'Browser approval manager','phone'=>$phone,'pin_hash'=>'3579',
                    'must_change_pin'=>false,'is_active'=>true,'branch_id'=>$branch->id]);
                app(StaffRoleService::class)->assign($manager,'manager');
            }
        });
    });
}

// Isolated operations journeys use real pre-enrolled synthetic owners and approved devices.
// These fixtures run only after the dedicated testing database guard above.
foreach (['desktop','mobile'] as $label) {
    DB::transaction(function () use ($label) {
        $business=Business::create(['name'=>'Operations Browser Supermarket','subdomain'=>'e2e-operations-'.$label,
            'plan_id'=>Plan::where('code','e2e')->firstOrFail()->id,'owner_phone'=>'0712222201','is_active'=>true]);
        app(TenantContext::class)->run($business,function () use ($label) {
            app(StaffRoleService::class)->provision();$branch=\App\Models\Branch::create(['name'=>'Main','is_active'=>true]);
            foreach (['owner'=>'0712222201','cashier'=>'0712222202','stockkeeper'=>'0712222203'] as $role=>$phone) {
                $user=User::create(['name'=>'Operations '.$role,'phone'=>$phone,'pin_hash'=>$role==='owner'?'654321':'2468',
                    'must_change_pin'=>false,'is_active'=>true,'branch_id'=>$branch->id]);
                app(StaffRoleService::class)->assign($user,$role);
                if($role==='owner'){$user->totp_secret='JBSWY3DPEHPK3PXP';$user->totp_confirmed_at=now();$user->save();}
                Device::create(['device_uid'=>'e2e-operations-'.$label.'-'.$role,'user_id'=>$user->id,'name'=>'Operations test device','status'=>'approved']);
                if($role==='stockkeeper')$keeper=$user;
            }
            $product=\App\Models\Product::create(['name'=>'Operations water','quantity_type'=>'counted','unit'=>'bottle','selling_price'=>1000,'buying_price'=>100,'reorder_level_atoms'=>0,'tracks_expiry'=>false,'is_active'=>true]);
            app(\App\Services\StockService::class)->receive($keeper,$product,['operation_id'=>(string)\Illuminate\Support\Str::uuid(),'batch_number'=>'OPERATIONS-STOCK','qty'=>'10','cost_price'=>100,'expiry_date'=>null]);
        });
    });
}

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
require __DIR__.'/banking-fixture.php';
require __DIR__.'/remaining-fixture.php';
require __DIR__.'/display-fixture.php';
require __DIR__.'/loss-fixture.php';
require __DIR__.'/margin-fixture.php';

// The guarded browser fixture uses real email/password authentication. These
// synthetic credentials never touch operational accounts or mail delivery.
foreach (Business::orderBy('id')->get() as $emailFixtureBusiness) {
    app(TenantContext::class)->run($emailFixtureBusiness, function () {
        foreach (User::orderBy('id')->get() as $emailFixtureUser) {
            $emailFixtureUser->email = '0'.substr($emailFixtureUser->phone, 3).'@example.test';
            $emailFixtureUser->password_hash = 'browser-email-password-42';
            $emailFixtureUser->must_change_pin = false;
            $emailFixtureUser->save();
        }
    });
}
