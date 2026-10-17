<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\SharedHostingProcess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunSharedHosting extends Command
{
    protected $signature = 'bizismart:shared-run';
    protected $description = 'Run finite, serialized background work from one five-minute shared-hosting cron entry';

    public function handle(SharedHostingProcess $process): int
    {
        if (config('hosting.profile') !== 'shared' || config('cache.stores.'.config('cache.default').'.driver') !== 'database'
            || config('cache.stores.'.(config('cache.limiter') ?: config('cache.default')).'.driver') !== 'database'
            || config('session.driver') !== 'database' || config('queue.default') !== 'database') {
            $this->error('Shared hosting requires the shared profile and database cache, limiter, sessions and queue.');
            return self::FAILURE;
        }
        if (!function_exists('proc_open')) { $this->error('Finite child processes require proc_open.'); return self::FAILURE; }
        $lock = Cache::lock('infra:shared-run', 120);
        if (!$lock->get()) { $this->info('A bounded shared run is already active.'); return self::SUCCESS; }
        $started = hrtime(true);
        $failed = 0; $completed = 0;
        try {
            $cursor = Cache::get('infra:shared-cursor', ['business' => 0, 'task' => 0]);
            $first = null;
            while (($remaining = 45 - (hrtime(true) - $started) / 1e9) > 2) {
                $shop = Business::where('is_active', true)->where('id', '>', (int) $cursor['business'])->orderBy('id')->first();
                if (!$shop) {
                    $cursor = ['business' => 0, 'task' => ((int) $cursor['task'] + 1) % count(SharedHostingUnit::TASKS)];
                    $shop = Business::where('is_active', true)->orderBy('id')->first();
                }
                if (!$shop) break;
                $unit = [$shop->id, (int) $cursor['task']];
                if ($unit === $first) break;
                $first ??= $unit;
                // Persist before work: killed/failed units are revisited next cycle, never monopolize every cron tick.
                $cursor = ['business' => $shop->id, 'task' => $unit[1]];
                Cache::put('infra:shared-cursor', $cursor, now()->addDays(30));
                if ($process->run($shop->id, SharedHostingUnit::TASKS[$unit[1]], min(25, $remaining - 1))) $completed++;
                else $failed++;
            }
            Cache::put('infra:shared-last-run', ['at' => now()->toISOString(), 'completed' => $completed, 'failed' => $failed], now()->addDays(7));
        } finally { $lock->release(); }
        $this->info("Finite shared run finished: {$completed} units completed, {$failed} failed or timed out. Delivery is not guaranteed by this command.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
