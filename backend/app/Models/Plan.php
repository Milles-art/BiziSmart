<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code', 'name', 'price_monthly', 'limits', 'features'];
    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['price_monthly' => 'integer', 'limits' => 'array', 'features' => 'array'];
    }
}
