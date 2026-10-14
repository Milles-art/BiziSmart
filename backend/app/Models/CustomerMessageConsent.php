<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class CustomerMessageConsent extends Model {use BelongsToBusiness,HasPublicId;public const PUBLIC_ID_PREFIX='consent_';protected $guarded=['id','business_id','public_id'];protected function casts():array{return ['whatsapp'=>'boolean','sms'=>'boolean','receipts'=>'boolean','debt_reminders'=>'boolean','revision'=>'integer','evidence'=>'encrypted'];}}
