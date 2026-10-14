<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class ExpenseAttachment extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='ephoto_';protected $guarded=['id','business_id','public_id'];protected $hidden=['id','business_id','expense_id','actor_id','device_id','fingerprint','path'];protected function casts():array{return ['path'=>'encrypted','bytes'=>'integer','width'=>'integer','height'=>'integer'];}}
