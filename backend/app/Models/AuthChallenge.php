<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class AuthChallenge extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id'];
    protected $hidden = ['id', 'business_id', 'user_id', 'device_id', 'token_hash', 'setup_secret'];
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'attempts' => 'integer', 'two_factor_verified' => 'boolean', 'setup_secret' => 'encrypted'];
    }
}
