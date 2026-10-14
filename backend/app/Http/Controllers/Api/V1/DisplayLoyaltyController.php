<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Incentives\LoyaltyCardRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\{Customer,LoyaltyCard};
use App\Services\{LoyaltyCardService,CommissionLeaderboardService};
use Illuminate\Http\Request;
class DisplayLoyaltyController {
 public function __construct(private LoyaltyCardService $cards,private CommissionLeaderboardService $commissions){}
 private function cardResource(?LoyaltyCard $card):?array{return $card?['id'=>$card->publicId(),'token'=>$card->active?$card->token:null,'active'=>$card->active,'created_at'=>$card->created_at->toIso8601String()]:null;}
 public function card(Request $r,Customer $customer){return ApiResponse::success(['card'=>$this->cardResource($this->cards->current($customer))]);}
 public function issue(LoyaltyCardRequest $r,Customer $customer){$result=$this->cards->issue($r->user(),$customer,$r->validated('operation_id'));return ApiResponse::success(['card'=>$this->cardResource($result['card']),'replayed'=>$result['replayed']],$result['replayed']?200:201);}
 public function resolve(Request $r){$input=$r->validate(['token'=>['required','string','regex:/^BSL:[a-f0-9]{32}$/D'],'business_id'=>['prohibited']]);return ApiResponse::success((new CustomerResource($this->cards->resolve($input['token'])))->resolve());}
 public function leaderboard(Request $r){abort_unless($r->user()->hasRole('owner'),403);$input=$r->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:100']]);$result=$this->commissions->report($r->user(),$input['page']??1,$input['per_page']??30);return ApiResponse::success(['leaderboard'=>$result['leaderboard'],'basis'=>$result['basis']],meta:$result['meta']);}
}
