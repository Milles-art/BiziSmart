<?php
namespace App\Services;
use App\Exceptions\ApiException;
use App\Models\{Business,Product,ProductBarcode,ScaleLabelProfile,User};
use App\Support\ScaleLabel;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
class ScaleLabelService {
 public function __construct(private TenantContext $context,private FinancialActorService $actors){}
 public function profile():?array{return ScaleLabelProfile::orderByDesc('id')->first()?->profile;}
 public function configure(User $original,array $input):array{return DB::transaction(function()use($original,$input){$actor=$this->actors->lock($original,'manage_settings');abort_unless($actor->hasRole('owner'),403);Business::whereKey($this->context->requireId())->lockForUpdate()->firstOrFail();$fingerprint=SupervisorApprovalService::snapshotHash($input);$old=ScaleLabelProfile::where('operation_id',strtolower($input['operation_id']))->first();if($old){if($old->user_id!==$actor->id||$old->device_id!==$actor->currentAccessToken()->device_id||!hash_equals($old->request_fingerprint,$fingerprint))throw new ApiException('OPERATION_ID_REUSED','This reference belongs to another scale configuration.',409);return ['profile'=>$this->profile(),'replayed'=>true];}try{if($input['profile']!==null)ScaleLabel::validateProfile($input['profile']);}catch(\InvalidArgumentException $e){throw new ApiException('VALIDATION_ERROR',$e->getMessage(),422,'profile');}ScaleLabelProfile::create(['user_id'=>$actor->id,'device_id'=>$actor->currentAccessToken()->device_id,'operation_id'=>strtolower($input['operation_id']),'request_fingerprint'=>$fingerprint,'profile'=>$input['profile']]);activity('settings')->causedBy($actor)->event('scale_label_configured')->log('Scale label format configured');return ['profile'=>$input['profile'],'replayed'=>false];},3);}
 public function decode(string $label):array{if(ProductBarcode::where('barcode',$label)->exists())throw new ApiException('REGISTERED_BARCODE','This is a registered catalogue barcode. Use the catalogue item instead of decoding it as weight.',409);$profile=$this->profile();if(!$profile)throw new ApiException('SCALE_NOT_CONFIGURED','The owner must configure the scale label format first. Manual weight entry remains available.',409);try{$decoded=ScaleLabel::decode($label,$profile);}catch(\InvalidArgumentException $e){throw new ApiException('INVALID_SCALE_LABEL',$e->getMessage(),422,'label');}$product=Product::where('barcode',$decoded['product_code'])->where('quantity_type','weight')->where('is_active',true)->firstOrFail();return ['product'=>$product,'qty'=>$decoded['qty']];}
}
