<?php

namespace App\Jobs;

use App\Jobs\Middleware\UseTenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;

abstract class TenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels {
        __serialize as private serializeJobProperties;
    }

    public function __construct(public readonly int $businessId)
    {
        if ($businessId < 1) {
            throw new LogicException('A tenant job requires a business ID.');
        }
    }

    final public function middleware(): array
    {
        return [new UseTenantContext];
    }

    final public function __serialize(): array
    {
        $check = function ($value) use (&$check): void {
            if (is_object($value) && ! $value instanceof \DateTimeInterface && ! $value instanceof \DateInterval) {
                throw new LogicException('Tenant jobs carry plain scalar/array data, not objects or Eloquent models.');
            }
            if (is_array($value)) {
                foreach ($value as $item) { $check($item); }
            }
        };
        foreach ((new \ReflectionObject($this))->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($this)) {
                $check($property->getValue($this));
            }
        }
        return $this->serializeJobProperties();
    }
}
