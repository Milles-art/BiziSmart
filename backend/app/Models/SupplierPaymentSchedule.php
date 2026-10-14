<?php
namespace App\Models;
use App\Models\Concerns\{BelongsToBusiness,HasPublicId};
use Illuminate\Database\Eloquent\Model;
class SupplierPaymentSchedule extends Model {
 use BelongsToBusiness,HasPublicId;
 public const PUBLIC_ID_PREFIX='sched_';
 protected $guarded=['id','business_id','public_id'];
 protected $attributes=['status'=>'scheduled'];
 protected function casts():array{return ['amount'=>'integer','due_on'=>'immutable_date'];}
}
