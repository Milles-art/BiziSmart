<?php

use App\Console\Commands\SharedHostingUnit;
use App\Models\{Business, Plan};
use App\Services\SharedHostingProcess;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\{Cache, Schedule};
use Tests\Concerns\RefreshTenantDatabase;

uses(RefreshTenantDatabase::class);

beforeEach(function () {
    config(['hosting.profile' => 'shared', 'cache.default' => 'database', 'cache.limiter' => 'database',
        'session.driver' => 'database', 'queue.default' => 'database']);
    $plan = Plan::create(['code' => 'shared', 'name' => 'Shared', 'price_monthly' => 70000, 'limits' => [], 'features' => []]);
    $this->one = Business::create(['name' => 'One', 'subdomain' => 'one', 'plan_id' => $plan->id, 'owner_phone' => '255700000001']);
    $this->two = Business::create(['name' => 'Two', 'subdomain' => 'two', 'plan_id' => $plan->id, 'owner_phone' => '255700000002']);
    $this->inactive = Business::create(['name' => 'Inactive', 'subdomain' => 'inactive', 'plan_id' => $plan->id, 'owner_phone' => '255700000003', 'is_active' => false]);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('runs every finite task for active tenants using bounded child timeouts and a persistent cursor', function () {
    $seen = [];
    $this->mock(SharedHostingProcess::class)->shouldReceive('run')->times(14)->andReturnUsing(function ($id, $task, $timeout) use (&$seen) {
        expect($timeout)->toBeGreaterThan(0)->toBeLessThanOrEqual(25);
        $seen[] = [$id, $task]; return true;
    });
    $this->artisan('bizismart:shared-run')->assertSuccessful();
    foreach (SharedHostingUnit::TASKS as $task) {
        expect($seen)->toContain([$this->one->id, $task], [$this->two->id, $task]);
    }
    expect(Cache::get('infra:shared-last-run')['completed'])->toBe(14)
        ->and(Cache::get('infra:shared-last-run')['failed'])->toBe(0)
        ->and(Cache::get('infra:shared-cursor'))->toBe(['business' => $this->two->id, 'task' => 6]);
});

it('excludes overlapping cron invocations using a real database lock', function () {
    $held = Cache::lock('infra:shared-run', 120); expect($held->get())->toBeTrue();
    $this->mock(SharedHostingProcess::class)->shouldNotReceive('run');
    try { $this->artisan('bizismart:shared-run')->assertSuccessful(); }
    finally { $held->release(); }
    expect(Cache::get('infra:shared-last-run'))->toBeNull();
});

it('retains failures without repeatedly blocking later tenants or leaking child output', function () {
    Cache::put('infra:shared-cursor', ['business' => $this->one->id, 'task' => 3], 600);
    $seen = [];
    $this->mock(SharedHostingProcess::class)->shouldReceive('run')->times(14)->andReturnUsing(function ($id, $task) use (&$seen) {
        $seen[] = [$id, $task]; return $id !== $this->one->id;
    });
    $this->artisan('bizismart:shared-run')->assertFailed();
    expect($seen[0])->toBe([$this->two->id, 'send'])
        ->and(Cache::get('infra:shared-last-run')['failed'])->toBe(7)
        ->and(Cache::lock('infra:shared-run', 1)->get())->toBeTrue();
});

it('refuses unsafe hosting configurations before doing any work', function ($setting, $value) {
    config([$setting => $value]);
    $this->mock(SharedHostingProcess::class)->shouldNotReceive('run');
    $this->artisan('bizismart:shared-run')->assertFailed();
})->with([['hosting.profile', 'standard'], ['cache.default', 'array'], ['cache.limiter', 'file'], ['session.driver', 'file'], ['queue.default', 'sync']]);

it('refuses unrecognized units and never establishes their tenant context', function () {
    $this->artisan('bizismart:shared-unit', ['business' => $this->one->id, 'task' => 'payments'])->assertFailed();
    $this->artisan('bizismart:shared-unit', ['business' => '../', 'task' => 'send'])->assertFailed();
    expect(app(TenantContext::class)->id())->toBeNull();
});

it('registers no unrestricted recurring schedules for the shared profile', function () {
    // Reload the exact registration against an empty scheduler with shared config.
    $schedule = new \Illuminate\Console\Scheduling\Schedule();
    Schedule::swap($schedule);
    require base_path('routes/console.php');
    expect($schedule->events())->toBe([]);
});

it('bounds outbox attempts and excludes uncertain accepted and foreign-tenant deliveries', function () {
    $context = app(TenantContext::class);
    $make = function ($event, $status = 'queued') {
        return \App\Models\MessageDelivery::create(['event_key' => hash('sha256', $event), 'channel' => 'sms',
            'purpose' => 'receipt', 'recipient' => '255700000001', 'message' => 'Test-only receipt', 'status' => $status]);
    };
    $ids = $context->run($this->one, function () use ($make) {
        $ids = [];
        for ($i = 0; $i < 12; $i++) $ids[] = $make('queued'.$i)->id;
        $make('uncertain', 'uncertain'); $make('accepted', 'accepted');
        return $ids;
    });
    $foreign = $context->run($this->two, fn () => $make('foreign'));
    $this->mock(\App\Services\PlanFeatureService::class)->shouldReceive('features')->once()->andReturn(['messaging' => true]);
    $messages = $this->mock(\App\Services\MessagingService::class);
    $messages->shouldReceive('stale')->once();
    $attempted = [];
    $messages->shouldReceive('dispatchOne')->times(10)->andReturnUsing(function ($id) use (&$attempted, $context) {
        expect($context->requireId())->toBe($this->one->id);
        $attempted[] = $id;
        // Definite configuration blocking may not select the same row twice in one unit.
        \App\Models\MessageDelivery::whereKey($id)->update(['status' => 'blocked']);
    });
    $this->artisan('bizismart:shared-unit', ['business' => $this->one->id, 'task' => 'send'])->assertSuccessful();
    expect($attempted)->toBe(array_slice($ids, 0, 10));
    $context->run($this->two, fn () => expect($foreign->fresh()->status)->toBe('queued'));
    expect($context->id())->toBeNull();
});
