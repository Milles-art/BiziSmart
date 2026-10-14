<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Incentives\ScaleProfileRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Services\ScaleLabelService;
use Illuminate\Http\Request;
class ScaleLabelController {
 public function __construct(private ScaleLabelService $scales){}
 public function profile(){return ApiResponse::success(['profile'=>$this->scales->profile(),'format'=>'ean13_weight_grams','hardware_verified'=>false]);}
 public function configure(ScaleProfileRequest $r){return ApiResponse::success($this->scales->configure($r->user(),$r->validated()));}
 public function decode(Request $r){$input=$r->validate(['label'=>['required','string','regex:/^[0-9]{13}$/D'],'business_id'=>['prohibited']]);$decoded=$this->scales->decode($input['label']);return ApiResponse::success(['product'=>(new ProductResource($decoded['product']))->resolve(),'qty'=>$decoded['qty']]);}
}
