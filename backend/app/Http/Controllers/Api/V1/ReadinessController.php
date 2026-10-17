<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Readiness for load-balancer/probe checks. Public by necessity, so it
 * reports only booleans: no versions, counts, secrets or configuration.
 */
class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => false, 'migrations' => false, 'cache' => false];
        try {
            DB::select('SELECT 1');
            $checks['database'] = true;
            $applied = DB::table('migrations')->pluck('migration')->all();
            $directory = database_path('migrations');
            $files = is_dir($directory) && is_readable($directory) ? glob($directory.'/*.php') : false;
            $required = $files === false ? [] : array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), $files);
            $checks['migrations'] = $required !== [] && array_diff($required, $applied) === [];
        } catch (\Throwable) {
            // Degraded: booleans above stay false; details never leave the server.
        }
        try {
            // Both application locks and rate limiting must share durable state.
            // A successful put/get on an array or per-host file store proves neither.
            $stores = array_unique([config('cache.default'), config('cache.limiter') ?: config('cache.default')]);
            $checks['cache'] = true;
            foreach ($stores as $name) {
                if (! is_string($name) || ! in_array(config('cache.stores.'.$name.'.driver'), ['redis', 'database', 'memcached', 'dynamodb'], true)) {
                    $checks['cache'] = false;
                    break;
                }
                $cache = Cache::store($name);
                $key = 'infra:ready:'.bin2hex(random_bytes(16));
                $value = bin2hex(random_bytes(16));
                try {
                    if ($cache->put($key, $value, 10) !== true || $cache->get($key) !== $value) {
                        $checks['cache'] = false;
                    }
                } finally {
                    $cache->forget($key);
                }
            }
        } catch (\Throwable) {
            $checks['cache'] = false;
        }
        $ready = $checks['database'] && $checks['migrations'] && $checks['cache'];
        return ApiResponse::success(['ready' => $ready, 'checks' => $checks], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store, private');
    }
}
