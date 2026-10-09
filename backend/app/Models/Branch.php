<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use BelongsToBusiness, HasPublicId;

    public const PUBLIC_ID_PREFIX = 'br_';
    protected $attributes = ['is_active' => 1];
    protected $fillable = ['name', 'address', 'gps_lat', 'gps_lng', 'is_active'];
    protected $hidden = ['id', 'public_id', 'business_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'address' => 'encrypted', 'gps_lat' => 'decimal:7', 'gps_lng' => 'decimal:7'];
    }
}
