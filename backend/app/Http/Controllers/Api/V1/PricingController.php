<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Pricing\{SavePricingRequest,CancelPricingRequest};
use App\Http\Resources\PricingRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\PricingRule;
use App\Services\PricingService;
use Illuminate\Http\{Request,JsonResponse};
class PricingController {
 public function __construct(private PricingService $pricing){}
 public function index(Request $request):JsonResponse{abort_unless($request->user()->hasRole('owner'),403);$input=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);$rules=PricingRule::with(['targets.product','targets.packaging','targets.batch'])->orderByDesc('id')->paginate($input['per_page']??50);return ApiResponse::success(['rules'=>PricingRuleResource::collection($rules->items())->resolve()],meta:['page'=>$rules->currentPage(),'per_page'=>$rules->perPage(),'total'=>$rules->total()]);}
 public function preview(SavePricingRequest $r):JsonResponse{return ApiResponse::success($this->pricing->preview($r->user(),$r->validated()));}
 public function store(SavePricingRequest $r):JsonResponse{$result=$this->pricing->save($r->user(),$r->validated());return ApiResponse::success(['rule'=>(new PricingRuleResource($result['rule']))->resolve(),'replayed'=>$result['replayed']],$result['replayed']?200:201);}
 public function cancelPreview(CancelPricingRequest $r,PricingRule $rule):JsonResponse{return ApiResponse::success($this->pricing->cancelPreview($r->user(),$rule,$r->validated()));}
 public function cancel(CancelPricingRequest $r,PricingRule $rule):JsonResponse{$result=$this->pricing->cancel($r->user(),$rule,$r->validated());return ApiResponse::success(['rule'=>(new PricingRuleResource($result['rule']))->resolve(),'replayed'=>$result['replayed']]);}
}
