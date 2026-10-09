<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = app(TenantContext::class)->id();
        if ($id === null) {
            $builder->whereRaw('1 = 0');
        } else {
            $builder->where($model->qualifyColumn('business_id'), $id);
        }
    }
}
