<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Finance\CashDropRequest;
use App\Http\Responses\ApiResponse;
use App\Models\{CashDrop,User};
use App\Services\CashDropService;
use Illuminate\Http\Request;
class CashDropController {
 public function index(Request $r,CashDropService $s){$v=$r->validate(['page'=>['sometimes','integer','min:1']]);$p=$s->overview($r->user())->paginate(25);return ApiResponse::success(['drops'=>array_map(fn($d)=>$s->view($d),$p->items())],meta:['page'=>$p->currentPage(),'per_page'=>25,'total'=>$p->total()]);}
 public function receivers(Request $r,CashDropService $s){$actor=$r->user();$people=User::where('is_active',true)->where('must_change_pin',false)->whereHas('roles',fn($q)=>$q->where('name','owner')->orWhere(fn($q)=>$q->where('name','manager')->where('users.branch_id',$actor->branch_id??0)))->orderBy('name')->limit(100)->get();return ApiResponse::success(['receivers'=>$people->filter(fn($u)=>$s->receiverEligible($u,$actor->branch))->map(fn($u)=>['id'=>$u->publicId(),'name'=>$u->name,'role'=>$u->getRoleNames()->first()])->values()->all()]);}
 public function preview(CashDropRequest $r,CashDropService $s){abort_if($r->has('review_hash'),422);return ApiResponse::success($s->preview($r->user(),$r->validated()));}
 public function store(CashDropRequest $r,CashDropService $s){$r->validate(['review_hash'=>['required']]);$data=$s->create($r->user(),$r->validated());return ApiResponse::success($data,$data['replayed']?200:201);}
 public function acknowledge(Request $r,CashDrop $drop,CashDropService $s){return ApiResponse::success($s->acknowledge($r->user(),$drop,$r->validate(['operation_id'=>['required','uuid']])));}
 public function reverse(Request $r,CashDrop $drop,CashDropService $s){return ApiResponse::success($s->reverse($r->user(),$drop,$r->validate(['operation_id'=>['required','uuid'],'reason'=>['required','string','max:1000'],'cash_returned'=>['required','boolean','accepted']])));}
}
