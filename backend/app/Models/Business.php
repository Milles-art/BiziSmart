<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Business extends Model
{
    use HasPublicId;

    public const PUBLIC_ID_PREFIX = 'biz_';
    protected $attributes = ['is_active' => 1, 'vat_rate' => 18];
    protected $fillable = ['name', 'subdomain', 'plan_id', 'owner_phone', 'tin', 'vrn', 'vat_rate', 'address', 'phone', 'is_active', 'settings'];
    protected $hidden = ['id', 'public_id', 'plan_id', 'owner_phone', 'tin', 'vrn', 'address', 'phone'];

    protected static function booted(): void
    {
        static::saving(function (Business $business): void {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $business->subdomain)
                || in_array($business->subdomain, config('tenancy.reserved_subdomains'), true)) {
                throw new LogicException('Invalid or reserved business subdomain.');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'vat_rate' => 'integer', 'settings' => 'array',
            'owner_phone' => 'encrypted', 'tin' => 'encrypted', 'vrn' => 'encrypted', 'address' => 'encrypted', 'phone' => 'encrypted'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
