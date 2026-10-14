<?php

use App\Http\Responses\ApiResponse;
use App\Jobs\Middleware\UseTenantContext;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Concerns\BelongsToBusiness;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\Plan;
use App\Models\User;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantFiles;
use Database\Seeders\DemoSupermarketSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Fixtures\CountTenantBranches;
use Tests\Fixtures\PrivatePayloadJob;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['tenancy.domain' => 'bizismart.test']);
    $this->context = app(TenantContext::class);
    $plan = Plan::create(['code' => 'test', 'name' => 'Test', 'price_monthly' => 0, 'limits' => [], 'features' => []]);
    $this->alpha = Business::create(['name' => 'Alpha', 'subdomain' => 'alpha', 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
    $this->beta = Business::create(['name' => 'Beta', 'subdomain' => 'beta', 'plan_id' => $plan->id, 'owner_phone' => '0700000001']);
    $this->branchA = $this->context->run($this->alpha, fn () => Branch::create(['name' => 'Alpha Branch']));
    $this->branchB = $this->context->run($this->beta, fn () => Branch::create(['name' => 'Beta Branch']));
    $this->userA = $this->context->run($this->alpha, fn () => User::create([
        'name' => 'Alpha Staff', 'phone' => '0700000001', 'pin_hash' => '1234', 'branch_id' => $this->branchA->id,
    ]));
    $this->userB = $this->context->run($this->beta, fn () => User::create([
        'name' => 'Beta Staff', 'phone' => '0700000001', 'pin_hash' => '1234', 'branch_id' => $this->branchB->id,
    ]));
    $this->tokenA = $this->context->run($this->alpha, fn () => $this->userA->createToken('test', ['*'])->plainTextToken);
    $this->tokenB = $this->context->run($this->beta, fn () => $this->userB->createToken('test', ['*'])->plainTextToken);
    // Probe routes exist only inside this test application. No shop endpoint is published in S1.
    Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1/tenant-test')->group(function () {
        Route::get('/branches', fn () => ApiResponse::success(['names' => Branch::pluck('name')->all()]));
        Route::get('/branches/{branch}', fn (Branch $branch) => ApiResponse::success(['id' => $branch->publicId(), 'name' => $branch->name]));
    });
});

afterEach(function () { $this->context->clear(); });

it('fails closed without a tenant and scopes lists, lookups and OR queries', function () {
    expect(Branch::count())->toBe(0)->and(User::count())->toBe(0)->and(PersonalAccessToken::count())->toBe(0);
    $this->context->run($this->alpha, function () {
        expect(Branch::pluck('name')->all())->toBe(['Alpha Branch'])
            ->and(Branch::find($this->branchB->id))->toBeNull()
            ->and(Branch::where('name', 'Alpha Branch')->orWhere('name', 'Beta Branch')->count())->toBe(1);
    });
    $this->context->run($this->beta, fn () => expect(Branch::pluck('name')->all())->toBe(['Beta Branch']));
});

it('ignores force-filled business input and generates public IDs on creation', function () {
    $this->context->run($this->alpha, function () {
        $branch = new Branch(['name' => 'New']);
        $branch->forceFill(['business_id' => $this->beta->id, 'public_id' => 'client-value']);
        $branch->save();
        expect((int) $branch->business_id)->toBe($this->alpha->id)
            ->and($branch->publicId())->toMatch('/^br_[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($branch->toArray())->not->toHaveKeys(['id', 'business_id', 'public_id']);
    });
});

it('blocks creation without a tenant', fn () => Branch::create(['name' => 'Unsafe']))->throws(LogicException::class);

it('blocks retained models across tenant switches even for quiet operations', function (string $operation) {
    $this->context->set($this->beta);
    $this->branchA->name = 'Changed';
    expect(fn () => $this->branchA->$operation())->toThrow(LogicException::class);
    $this->context->run($this->alpha, fn () => expect(Branch::first()->name)->toBe('Alpha Branch'));
})->with(['save', 'saveQuietly', 'delete', 'deleteQuietly', 'fresh', 'refresh', 'refreshForUpdate']);

it('scopes bulk writes and forbids tenant reassignment', function () {
    $this->context->run($this->alpha, function () {
        expect(Branch::query()->update(['name' => 'Updated Alpha']))->toBe(1);
        expect(fn () => Branch::query()->update(['business_id' => $this->beta->id]))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->update(['branches.business_id' => $this->beta->id]))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->increment('business_id'))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->incrementEach(['business_id' => 1]))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->decrementEach(['business_id' => 1]))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->touch('business_id'))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->upsert([['name' => 'Unsafe']], ['id']))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->insert([['business_id' => $this->beta->id]]))->toThrow(LogicException::class);
        expect(fn () => Branch::query()->insertGetId(['business_id' => $this->beta->id]))->toThrow(LogicException::class);
    });
    $this->context->run($this->beta, fn () => expect(Branch::first()->name)->toBe('Beta Branch'));
});

it('rejects reassignment of a loaded record within its current tenant', function () {
    $this->context->set($this->alpha);
    $this->branchA->business_id = $this->beta->id;
    expect(fn () => $this->branchA->save())->toThrow(LogicException::class);
});

it('database constraints prevent cross-tenant branch and device associations', function (string $relation) {
    $this->context->set($this->alpha);
    if ($relation === 'branch') {
        expect(fn () => User::create(['name' => 'Cross', 'phone' => '0700000002', 'pin_hash' => '1234', 'branch_id' => $this->branchB->id]))->toThrow(QueryException::class);
    } else {
        expect(fn () => Device::create(['name' => 'Cross', 'device_uid' => 'cross', $relation => $this->userB->id]))->toThrow(QueryException::class);
    }
})->with(['branch', 'user_id', 'approved_by']);

it('encrypts staff data and hashes PINs while preserving per-tenant phone uniqueness', function () {
    $raw = DB::table('users')->where('business_id', $this->alpha->id)->where('id', $this->userA->id)->first();
    expect($raw->phone)->not->toBe('0700000001')->and($raw->name)->not->toBe('Alpha Staff')
        ->and(Hash::check('1234', $raw->pin_hash))->toBeTrue();
    $this->context->run($this->alpha, function () {
        expect(User::forPhone('0700000001')->first()->id)->toBe($this->userA->id);
        expect(fn () => User::create(['name' => 'Duplicate', 'phone' => '0700000001', 'pin_hash' => '4321']))->toThrow(QueryException::class);
    });
    $this->context->run($this->beta, fn () => expect(User::forPhone('0700000001')->first()->id)->toBe($this->userB->id));
    expect($this->userA->toArray())->not->toHaveKeys(['pin_hash', 'phone_lookup', 'phone', 'business_id', 'id']);
});

it('allows the same device UID across businesses but prevents duplicates within one', function () {
    $this->context->run($this->alpha, fn () => Device::create(['name' => 'Alpha Device', 'device_uid' => 'same-device', 'user_id' => $this->userA->id]));
    $this->context->run($this->beta, fn () => Device::create(['name' => 'Beta Device', 'device_uid' => 'same-device', 'user_id' => $this->userB->id]));
    $this->context->run($this->alpha, function () {
        expect(Device::first()->name)->toBe('Alpha Device');
        expect(fn () => Device::create(['name' => 'Duplicate', 'device_uid' => 'same-device']))->toThrow(QueryException::class);
    });
    $this->context->run($this->beta, fn () => expect(Device::first()->name)->toBe('Beta Device'));
});

it('keeps soft-deleted staff scoped and rejects their old token', function () {
    $this->context->run($this->alpha, function () {
        $this->userA->delete();
        expect(User::count())->toBe(0)->and(User::withTrashed()->count())->toBe(1);
    });
    $this->context->run($this->beta, fn () => expect(User::withTrashed()->find($this->userA->id))->toBeNull());
    $this->withToken($this->tokenA)->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches')->assertUnauthorized();
});

it('resolves the exact subdomain before authentication and route binding', function () {
    $this->withToken($this->tokenA)->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches')
        ->assertOk()->assertJsonPath('data.names', ['Alpha Branch']);
    expect($this->context->id())->toBeNull();
    $this->withToken($this->tokenB)->getJson('http://beta.bizismart.test/api/v1/tenant-test/branches')
        ->assertOk()->assertJsonPath('data.names', ['Beta Branch']);
    $this->withToken($this->tokenA)->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches/'.$this->branchA->publicId())
        ->assertOk()->assertJsonPath('data.id', $this->branchA->publicId());
    expect($this->context->id())->toBeNull();
});

it('returns 404 for another tenants resource and internal numeric IDs', function (string $id) {
    $target = match ($id) { 'other' => $this->branchB->publicId(), 'numeric' => (string) $this->branchA->id, 'raw' => $this->branchA->public_id };
    $this->withToken($this->tokenA)->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches/'.$target)
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    expect($this->context->id())->toBeNull();
})->with(['other', 'numeric', 'raw']);

it('rejects token reuse against a different business without retaining authentication', function () {
    $this->withToken($this->tokenA)->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches')->assertOk();
    $this->getJson('http://beta.bizismart.test/api/v1/tenant-test/branches')->assertUnauthorized();
    $this->withHeader('Authorization', '')->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches')->assertUnauthorized();
    expect($this->context->id())->toBeNull();
});

it('rejects unknown, nested, reserved and forged domains', function (string $host) {
    $this->withToken($this->tokenA)->withHeader('X-Business-Id', (string) $this->alpha->id)
        ->withHeader('X-Forwarded-Host', 'alpha.bizismart.test')
        ->getJson('http://'.$host.'/api/v1/tenant-test/branches?business_id='.$this->alpha->id)
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    expect($this->context->id())->toBeNull();
})->with(['unknown.bizismart.test', 'nested.alpha.bizismart.test', 'admin.bizismart.test', '127.0.0.1', 'alpha.bizismart.test.evil.test']);

it('rejects inactive businesses and inactive users', function () {
    $this->context->run($this->alpha, fn () => $this->userA->update(['is_active' => false]));
    $this->withToken($this->tokenA)->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches')->assertUnauthorized();
    $this->alpha->update(['is_active' => false]);
    $this->getJson('http://alpha.bizismart.test/api/v1/tenant-test/branches')->assertNotFound();
});

it('cannot mint a token for a retained user from another business', function () {
    $this->context->set($this->beta);
    expect(fn () => $this->userA->createToken('wrong-business'))->toThrow(LogicException::class);
});

it('restores nested tenant context after successful and failed callbacks', function () {
    $this->context->set($this->alpha);
    expect(fn () => $this->context->run($this->beta, fn () => throw new RuntimeException('Probe')))->toThrow(RuntimeException::class);
    expect($this->context->id())->toBe($this->alpha->id);
});

it('runs queued jobs with their own tenant and isolates their cache entries', function () {
    $this->context->run($this->beta, fn () => Branch::create(['name' => 'Second Beta Branch']));
    dispatch_sync(new CountTenantBranches($this->alpha->id));
    dispatch_sync(new CountTenantBranches($this->beta->id));
    expect($this->context->id())->toBeNull();
    $this->context->run($this->alpha, fn () => expect(app(TenantCache::class)->get('branch-count'))->toBe(1));
    $this->context->run($this->beta, fn () => expect(app(TenantCache::class)->get('branch-count'))->toBe(2));
    expect(fn () => app(TenantCache::class)->get('branch-count'))->toThrow(LogicException::class);
});

it('rejects jobs without a valid business and clears context after job errors', function () {
    $middleware = new UseTenantContext;
    expect(fn () => $middleware->handle(new stdClass, fn () => throw new RuntimeException('Must not run')))->toThrow(LogicException::class);
    expect(fn () => $middleware->handle((object) ['businessId' => 999999], fn () => null))->toThrow(LogicException::class);
    expect(fn () => $middleware->handle((object) ['businessId' => $this->alpha->id], fn () => throw new RuntimeException('Probe')))->toThrow(RuntimeException::class);
    expect($this->context->id())->toBeNull();
});

it('does not serialize Eloquent models into tenant jobs', function () {
    $job = new CountTenantBranches($this->alpha->id);
    $job->delay($this->branchA);
    expect(fn () => serialize($job))->toThrow(LogicException::class);
    expect(fn () => serialize(new PrivatePayloadJob($this->alpha->id, $this->branchA)))->toThrow(LogicException::class);
    $copy = unserialize(serialize(new PrivatePayloadJob($this->alpha->id, ['branch_id' => $this->branchA->id])));
    expect($copy->payload())->toBe(['branch_id' => $this->branchA->id]);
});

it('refuses database refresh against a development database', function () {
    $original = config('database.connections.mysql.database');
    config(['database.connections.mysql.database' => 'bizismart']);
    try {
        $hook = new ReflectionMethod($this, 'beforeRefreshingDatabase');
        expect(fn () => $hook->invoke($this))->toThrow(LogicException::class);
    } finally {
        config(['database.connections.mysql.database' => $original]);
    }
    $this->context->run($this->alpha, fn () => expect(Branch::count())->toBe(1));
});

it('separates cache and file paths and blocks directory traversal', function () {
    Storage::fake('local');
    $this->context->run($this->alpha, function () {
        app(TenantCache::class)->put('same-key', 'Alpha', 60);
        app(TenantFiles::class)->put('receipts/same.txt', 'Alpha');
    });
    $this->context->run($this->beta, function () {
        expect(app(TenantCache::class)->get('same-key'))->toBeNull();
        app(TenantCache::class)->put('same-key', 'Beta', 60);
        app(TenantFiles::class)->put('receipts/same.txt', 'Beta');
        expect(app(TenantFiles::class)->get('receipts/same.txt'))->toBe('Beta');
        foreach (['../alpha.txt', '/absolute.txt', 'C:/file.txt', 'receipts\\escape.txt', 'receipts/../../escape.txt'] as $path) {
            expect(fn () => $this->context->filePath($path))->toThrow(LogicException::class);
        }
    });
    $this->context->run($this->alpha, function () {
        expect(app(TenantCache::class)->get('same-key'))->toBe('Alpha')
            ->and(app(TenantFiles::class)->get('receipts/same.txt'))->toBe('Alpha');
    });
    expect(fn () => app(TenantFiles::class)->put('file.txt', 'Unsafe'))->toThrow(LogicException::class);
});

it('covers every tenant table with a scoped model and forbids scope escapes in application code', function () {
    $models = [];
    foreach (glob(app_path('Models/*.php')) as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (is_subclass_of($class, Illuminate\Database\Eloquent\Model::class) && (new ReflectionClass($class))->isInstantiable()) {
            $model = new $class;
            $models[$model->getTable()] = $class;
        }
    }
    foreach (Schema::getTables() as $table) {
        if (Schema::hasColumn($table['name'], 'business_id')) {
            expect($models)->toHaveKey($table['name']);
            expect(class_uses_recursive($models[$table['name']]))->toContain(BelongsToBusiness::class);
        }
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source = file_get_contents($file->getPathname());
            // AGENTS permits the provider webhook's persisted order lookup before tenant selection.
            // Allow only this exact mapping call, retaining the guard for any additional escape.
            if ($file->getRealPath() === realpath(app_path('Http/Controllers/Api/V1/SelcomCallbackController.php'))) {
                $source = str_replace('MobilePayment::withoutGlobalScopes()', 'MobilePayment::query()', $source);
            }
            expect($source)->not->toMatch('/\b(?:withoutGlobalScopes?|newQueryWithoutScopes|newQueryWithoutScope|newQueryWithoutRelationships)\s*\(/');
        }
    }
});

it('seeds two safe demo supermarkets idempotently without usable staff access', function () {
    $this->seed(DemoSupermarketSeeder::class);
    $this->seed(DemoSupermarketSeeder::class);
    expect(Business::whereIn('subdomain', ['demo-alpha', 'demo-beta'])->count())->toBe(2);
    foreach (Business::whereIn('subdomain', ['demo-alpha', 'demo-beta'])->get() as $business) {
        $this->context->run($business, function () {
            expect(Branch::count())->toBe(1)->and(User::count())->toBe(1)->and(Device::count())->toBe(1)
                ->and(User::first()->is_active)->toBeFalse()->and(Device::first()->status)->toBe('pending')
                ->and(PersonalAccessToken::count())->toBe(0);
        });
    }
    expect($this->context->id())->toBeNull();
});
