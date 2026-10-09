<?php

namespace App\Tenancy;

use App\Models\Business;
use Closure;
use LogicException;

class TenantContext
{
    private ?int $businessId = null;

    public function id(): ?int
    {
        return $this->businessId;
    }

    public function requireId(): int
    {
        return $this->businessId ?? throw new LogicException('Tenant context is required.');
    }

    public function set(Business $business): void
    {
        if (! $business->exists || ! $business->is_active) {
            throw new LogicException('An active persisted business is required.');
        }
        $this->businessId = (int) $business->getKey();
        $this->resetPermissionContext();
    }

    public function clear(): void
    {
        $this->businessId = null;
        $this->resetPermissionContext();
    }

    public function run(Business $business, Closure $callback): mixed
    {
        $previous = $this->businessId;
        try {
            $this->set($business);
            return $callback();
        } finally {
            $this->businessId = $previous;
            $this->resetPermissionContext();
        }
    }

    public function cacheKey(string $key): string
    {
        if ($key === '' || str_contains($key, "\0")) {
            throw new LogicException('A valid cache key is required.');
        }
        return 'business:'.$this->requireId().':'.$key;
    }

    private function resetPermissionContext(): void
    {
        config(['permission.cache.key' => 'business:'.($this->businessId ?? 'none').':permissions']);
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $registrar->clearPermissionsCollection();
        $registrar->initializeCache();
    }

    public function filePath(string $path): string
    {
        // Application-generated relative paths only; reject traversal and Windows separators.
        foreach (explode('/', $path) as $part) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $part) || in_array($part, ['.', '..'], true)) {
                throw new LogicException('A safe relative file path is required.');
            }
        }
        return 'businesses/'.$this->requireId().'/'.$path;
    }
}
