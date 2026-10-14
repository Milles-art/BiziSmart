<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class LoyaltyCard extends Model {
 use BelongsToBusiness,HasPublicId;
 public const PUBLIC_ID_PREFIX='card_';
 protected $guarded=['id','business_id','public_id'];
 protected $hidden=['id','business_id','token','token_hash','request_fingerprint'];
 protected function casts():array {return ['token'=>'encrypted','active'=>'boolean'];}
}
