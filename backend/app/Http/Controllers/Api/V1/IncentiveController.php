<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Incentives\{ConfigureIncentivesRequest,CommissionPayoutRequest,ForecastRequest};
use App\Http\Responses\ApiResponse;
use App\Models\{Customer,User,IncentiveConfiguration,CommissionPayout,IncentiveEntry};
use App\Services\{IncentiveService,DemandForecastService};
use Illuminate\Http\{Request,JsonResponse};
class IncentiveController {
 public function __construct(private IncentiveService $incentives,private DemandForecastService $forecast){}
 public function configuration(Request $r):JsonResponse{abort_unless($r->user()->hasRole('owner'),403);return ApiResponse::success(['configuration'=>$this->configResource($this->incentives->configuration()),'redemption_enabled'=>$this->incentives->configuration()?->loyalty_point_value!==null]);}
 public function previewConfiguration(ConfigureIncentivesRequest $r):JsonResponse{return ApiResponse::success($this->incentives->previewConfiguration($r->user(),$r->validated()));}
 public function configure(ConfigureIncentivesRequest $r):JsonResponse{$result=$this->incentives->configure($r->user(),$r->validated());return ApiResponse::success(['configuration'=>$this->configResource($result['configuration']),'replayed'=>$result['replayed']],$result['replayed']?200:201);}
 private function configResource(?IncentiveConfiguration $c):?array{return $c?['id'=>$c->publicId(),'loyalty_tsh_per_point'=>$c->loyalty_tsh_per_point,'loyalty_point_value'=>$c->loyalty_point_value,'commission_basis_points'=>$c->commission_basis_points,'reason'=>$c->reason,'created_at'=>$c->created_at->toIso8601String()]:null;}
 public function loyalty(Request $r,Customer $customer):JsonResponse{abort_unless($r->user()->can('manage_customers'),403);return ApiResponse::success($this->incentives->loyalty($customer));}
 public function commissions(Request $r):JsonResponse{abort_unless($r->user()->hasRole('owner'),403);$input=$r->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);$users=User::whereIn('id',IncentiveEntry::select('cashier_id'))->orderBy('name')->orderBy('id')->paginate($input['per_page']??50);return ApiResponse::success(['commissions'=>$users->map(fn($u)=>$this->incentives->commission($u))->all()],meta:['page'=>$users->currentPage(),'per_page'=>$users->perPage(),'total'=>$users->total()]);}
 public function previewPayout(CommissionPayoutRequest $r):JsonResponse{return ApiResponse::success($this->incentives->previewPayout($r->user(),$r->validated()));}
 public function payout(CommissionPayoutRequest $r):JsonResponse{$result=$this->incentives->payout($r->user(),$r->validated());$p=$result['payout'];return ApiResponse::success(['payout'=>['id'=>$p->publicId(),'amount'=>$p->amount,'paid_on'=>$p->paid_on->toDateString(),'external_reference'=>$p->external_reference],'replayed'=>$result['replayed'],'external_transfer_executed'=>false],$result['replayed']?200:201);}
 public function forecast(ForecastRequest $r):JsonResponse{$result=$this->forecast->report($r->validated());return ApiResponse::success(['forecast'=>$result['forecast']],meta:$result['meta']);}
}
