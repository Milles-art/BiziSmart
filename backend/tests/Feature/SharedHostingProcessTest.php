<?php

use App\Services\SharedHostingProcess;

it('terminates an attached work unit that exceeds its deadline without retaining output', function () {
    $start = microtime(true);
    expect(app(SharedHostingProcess::class)->run(0, 'invalid', 0.01))->toBeFalse();
    expect(microtime(true) - $start)->toBeLessThan(5);
});

it('shares cache values and lock ownership across independent database connections', function () {
    \Tests\Support\GuardedTestDatabase::assertConnection();
    $name = 'shared_contender';
    config(['database.connections.'.$name => config('database.connections.mysql')]);
    $prefix = 'shared-regression:'.bin2hex(random_bytes(16)).':';
    $first = new \Illuminate\Cache\DatabaseStore(\Illuminate\Support\Facades\DB::connection(), 'cache', $prefix);
    $second = new \Illuminate\Cache\DatabaseStore(\Illuminate\Support\Facades\DB::connection($name), 'cache', $prefix);
    $held = $first->lock('cron', 30); $contender = $second->lock('cron', 30);
    try {
        expect($first->put('probe', 'shared', 30))->toBeTrue()->and($second->get('probe'))->toBe('shared');
        expect($held->get())->toBeTrue()->and($contender->get())->toBeFalse();
        expect($contender->release())->toBeFalse();
        expect($held->release())->toBeTrue()->and($contender->get())->toBeTrue();
    } finally {
        $held->release(); $contender->release(); $first->forget('probe');
        \Illuminate\Support\Facades\DB::purge($name);
    }
});
