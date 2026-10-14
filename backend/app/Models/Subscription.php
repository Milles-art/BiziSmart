<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class Subscription extends Model {
    use BelongsToBusiness,HasPublicId;
    public const PUBLIC_ID_PREFIX='sub_';
    protected $guarded=['id','business_id','public_id'];
    protected function casts():array{return ['starts_at'=>'immutable_datetime','ends_at'=>'immutable_datetime','revision'=>'integer'];}
}
