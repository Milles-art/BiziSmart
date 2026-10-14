<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class ProductImageCleanup extends Model
{
    use BelongsToBusiness;
    protected $fillable = ['path'];
    protected $hidden = ['id', 'business_id', 'path'];
}
