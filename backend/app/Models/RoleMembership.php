<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

class RoleMembership extends MorphPivot
{
    use BelongsToBusiness;

    protected $table = 'model_has_roles';
    public $timestamps = false;

    public function newEloquentBuilder($query)
    {
        return (new TenantPivotBuilder($query))->withGlobalScope(\App\Models\Scopes\BusinessScope::class, new \App\Models\Scopes\BusinessScope);
    }
}
