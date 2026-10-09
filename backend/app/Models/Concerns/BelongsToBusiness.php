<?php

namespace App\Models\Concerns;

use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\TenantBuilder;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToBusiness
{
    private ?int $creatingBusinessId = null;
    protected static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);
        static::creating(function ($model): void {
            // Even force-filled input cannot select a business.
            $model->business_id = app(TenantContext::class)->requireId();
        });
        static::updating(fn ($model) => $model->assertCurrentBusiness());
        static::deleting(fn ($model) => $model->assertCurrentBusiness());
        if (method_exists(static::class, 'restore')) {
            static::restoring(fn ($model) => $model->assertCurrentBusiness());
        }
    }

    public function assertCurrentBusiness(): void
    {
        $id = app(TenantContext::class)->requireId();
        $original = $this->getRawOriginal('business_id');
        $creatingInThisBusiness = $original === null && $this->creatingBusinessId === $id;
        if ((int) $this->business_id !== $id || (! $creatingInThisBusiness && (int) $original !== $id)) {
            throw new LogicException('A tenant record cannot cross a business boundary.');
        }
    }

    public function newEloquentBuilder($query)
    {
        // Also scope newModelQuery(), which Eloquent uses for quiet writes and refreshes.
        return (new TenantBuilder($query))->withGlobalScope(BusinessScope::class, new BusinessScope);
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            $this->assertCurrentBusiness();
        } else {
            $this->business_id = app(TenantContext::class)->requireId();
            $this->creatingBusinessId = (int) $this->business_id;
        }
        try {
            return parent::save($options);
        } finally {
            $this->creatingBusinessId = null;
        }
    }

    public function delete()
    {
        if ($this->exists) { $this->assertCurrentBusiness(); }
        return parent::delete();
    }

    public function fresh($with = [])
    {
        $this->assertCurrentBusiness();
        return parent::fresh($with);
    }

    public function refresh()
    {
        $this->assertCurrentBusiness();
        return parent::refresh();
    }

    public function refreshForUpdate()
    {
        $this->assertCurrentBusiness();
        return parent::refreshForUpdate();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
