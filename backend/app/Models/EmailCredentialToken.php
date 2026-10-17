<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class EmailCredentialToken extends Model
{
    use BelongsToBusiness;

    protected $guarded = ['id', 'business_id'];
    protected $hidden = ['id', 'business_id', 'user_id', 'token_hash', 'email'];
    protected function casts(): array
    {
        return ['email' => 'encrypted', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
