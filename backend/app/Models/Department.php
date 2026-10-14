<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use BelongsToBusiness, HasPublicId, SoftDeletes;
    public const PUBLIC_ID_PREFIX = 'dep_';
    protected $fillable = ['name', 'current_name'];
    protected $hidden = ['id', 'public_id', 'business_id', 'current_name'];
    public function assignedProducts(): HasMany { return $this->hasMany(Product::class)->withTrashed(); }
}
