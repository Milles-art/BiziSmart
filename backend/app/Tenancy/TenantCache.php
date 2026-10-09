<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\Cache;

class TenantCache
{
    public function __construct(private TenantContext $context) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->context->cacheKey($key), $default);
    }

    public function put(string $key, mixed $value, int $seconds): void
    {
        Cache::put($this->context->cacheKey($key), $value, $seconds);
    }

    public function forget(string $key): void
    {
        Cache::forget($this->context->cacheKey($key));
    }
}
