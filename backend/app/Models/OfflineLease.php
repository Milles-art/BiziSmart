<?php
namespace App\Models;

use App\Models\Concerns\{BelongsToBusiness, HasPublicId};
use Illuminate\Database\Eloquent\Model;

class OfflineLease extends Model
{
    use BelongsToBusiness, HasPublicId;
    public const PUBLIC_ID_PREFIX = 'ol_';
    protected $guarded = ['id', 'business_id', 'public_id'];
    protected $hidden = ['id', 'business_id', 'user_id', 'device_id', 'till_session_id', 'secret', 'catalogue'];
    protected function casts(): array {
        return ['secret'=>'encrypted', 'catalogue'=>'encrypted:array', 'issued_at'=>'immutable_datetime', 'expires_at'=>'immutable_datetime', 'revoked_at'=>'immutable_datetime', 'vat_rate'=>'integer'];
    }
}
