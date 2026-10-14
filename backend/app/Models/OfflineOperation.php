<?php
namespace App\Models;

use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;

class OfflineOperation extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'oo_';
    protected $guarded = ['id', 'business_id', 'public_id'];
    protected $hidden = ['id', 'business_id', 'lease_id', 'user_id', 'device_id', 'sale_id', 'payload', 'request_fingerprint', 'resolution_reason', 'resolved_by'];
    protected function casts(): array {
        return ['payload'=>'encrypted:array', 'result'=>'encrypted:array', 'conflicts'=>'array', 'resolution_reason'=>'encrypted', 'captured_at'=>'immutable_datetime', 'resolved_at'=>'immutable_datetime'];
    }
}
