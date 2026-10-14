<?php
namespace App\Models;

use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'cust_';
    protected $fillable = ['name','phone','credit_limit'];
    protected $hidden = ['id','business_id','public_id','phone_lookup'];
    protected function casts(): array { return ['name'=>'encrypted','phone'=>'encrypted','credit_limit'=>'integer','outstanding_balance'=>'integer']; }
    public static function phoneLookup(string $phone): string { return hash_hmac('sha256', \App\Support\StaffPhone::normalize($phone), config('app.key')); }
    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            if ($customer->isDirty('phone')) {
                $customer->phone = $customer->phone === null ? null : \App\Support\StaffPhone::normalize($customer->phone);
                $customer->phone_lookup = $customer->phone === null ? null : self::phoneLookup($customer->phone);
            }
        });
    }
}
