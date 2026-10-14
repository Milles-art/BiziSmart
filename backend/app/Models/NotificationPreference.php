<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class NotificationPreference extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='npref_';protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['in_app'=>'boolean','whatsapp'=>'boolean','sms'=>'boolean','revision'=>'integer'];}
}
