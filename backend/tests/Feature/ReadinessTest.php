<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Pure dependency probes: these tests never open or reset a database.
beforeEach(function () {
    // The throttle has its own store/integration tests; keep these probes pure.
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $this->probeDirectory = sys_get_temp_dir().'/bizismart-ready-'.bin2hex(random_bytes(8));
    mkdir($this->probeDirectory.'/migrations', 0700, true);
    file_put_contents($this->probeDirectory.'/migrations/2026_01_01_000001_example.php', '<?php');
    app()->useDatabasePath($this->probeDirectory);
    config(['cache.default' => 'redis', 'cache.limiter' => 'redis', 'cache.stores.redis.driver' => 'redis', 'permission.cache.store' => 'array']);
    // Tenant middleware resets Spatie's permission context even on public probes.
    // Keep that unrelated cache explicitly in memory while mocking probe stores.
    Cache::shouldReceive('store')->with('array')->andReturn(new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore));
});
afterEach(function () {
    @unlink($this->probeDirectory.'/migrations/2026_01_01_000001_example.php');
    @rmdir($this->probeDirectory.'/migrations');
    @rmdir($this->probeDirectory);
});

function readyDatabase(array $applied = ['2026_01_01_000001_example']): void
{
    DB::shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
    $query = Mockery::mock();
    $query->shouldReceive('pluck')->once()->with('migration')->andReturn(collect($applied));
    DB::shouldReceive('table')->once()->with('migrations')->andReturn($query);
}

function readyCache(bool $writeSucceeds = true): void
{
    $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
    $written = [];
    $cache->shouldReceive('put')->once()->andReturnUsing(function ($key, $value, $ttl) use (&$written, $writeSucceeds) {
        expect($key)->toStartWith('infra:ready:');
        expect($ttl)->toBe(10);
        $written[$key] = $value;
        return $writeSucceeds;
    });
    if ($writeSucceeds) {
        $cache->shouldReceive('get')->once()->andReturnUsing(function ($key) use (&$written) { return $written[$key] ?? null; });
    }
    $cache->shouldReceive('forget')->once()->andReturnTrue();
    Cache::shouldReceive('store')->once()->with('redis')->andReturn($cache);
}

it('reports liveness without opening any dependencies', function () {
    DB::shouldReceive('select')->never();
    Cache::shouldReceive('store')->with('redis')->never();
    $this->getJson('http://alpha.bizismart.test/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
});

it('prevents caching method errors before the readiness controller runs', function () {
    DB::shouldReceive('select')->never();
    Cache::shouldReceive('store')->with('redis')->never();
    $this->postJson('/api/v1/ready')->assertStatus(405)->assertHeader('Cache-Control', 'no-store, private');
});

it('reports successful shared dependency probes without leaking internals', function () {
    readyDatabase(); readyCache();
    $probe = $this->getJson('http://api.bizismart.test/api/v1/ready')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.ready', true)->json('data');
    expect($probe['checks'])->toBe(['database' => true, 'migrations' => true, 'cache' => true]);
    foreach (['mysql', 'redis', 'password', 'secret', 'laravel', 'php', '8.'] as $leak) {
        expect(strtolower(json_encode($probe)))->not->toContain($leak);
    }
});

it('rejects pending migrations', function () {
    readyDatabase([]); readyCache();
    $this->getJson('/api/v1/ready')->assertStatus(503)->assertJsonPath('data.checks.migrations', false);
});

it('rejects missing or empty migration sources', function ($missing) {
    readyDatabase(); readyCache();
    unlink($this->probeDirectory.'/migrations/2026_01_01_000001_example.php');
    if ($missing) { rmdir($this->probeDirectory.'/migrations'); }
    $this->getJson('/api/v1/ready')->assertStatus(503)->assertJsonPath('data.checks.migrations', false);
})->with([true, false]);

it('rejects nonshared application cache or rate limiter stores', function ($default, $limiter) {
    readyDatabase();
    config(['cache.default' => $default, 'cache.limiter' => $limiter]);
    if ($default === 'redis') { readyCache(); }
    else { Cache::shouldReceive('store')->with('redis')->never(); }
    $this->getJson('/api/v1/ready')->assertStatus(503)->assertJsonPath('data.checks.cache', false);
})->with([['array', 'redis'], ['file', 'file'], ['redis', 'file']]);

it('rejects failed cache writes instead of accepting an older probe value', function () {
    readyDatabase(); readyCache(false);
    $this->getJson('/api/v1/ready')->assertStatus(503)->assertJsonPath('data.checks.cache', false);
});

it('reports database failure without leaking the connection exception', function () {
    DB::shouldReceive('select')->once()->andThrow(new RuntimeException('password=private-host-secret'));
    readyCache();
    $response = $this->getJson('/api/v1/ready')->assertStatus(503)->assertJsonPath('data.checks.database', false)->assertJsonPath('data.checks.cache', true);
    expect($response->getContent())->not->toContain('private-host-secret');
});
