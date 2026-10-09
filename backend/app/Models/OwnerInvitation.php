<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class OwnerInvitation extends Model
{
    use BelongsToBusiness;
    protected $guarded = ['id', 'business_id'];
    protected $hidden = ['id', 'business_id', 'token_hash'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'consumed_at' => 'datetime']; }
}
