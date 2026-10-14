<?php
namespace App\Http\Controllers\Api\V1;
use App\Models\Expense;
use App\Services\ExpenseService;
use App\Http\Requests\Finance\{ExpenseRequest,ExpensePaymentRequest,ExpenseReversalRequest};
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
class ExpenseController {
 public function index(Request $request,ExpenseService $service){$v=$request->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:50']]);$p=Expense::orderByDesc('incurred_on')->orderByDesc('id')->paginate($v['per_page']??25);return ApiResponse::success(['expenses'=>array_map(fn($e)=>$service->view($e),$p->items())],meta:['page'=>$p->currentPage(),'total'=>$p->total(),'per_page'=>$p->perPage()]);}
 public function previewRecord(ExpenseRequest $r,ExpenseService $s){return ApiResponse::success($s->previewRecord($r->user(),$r->validated()));}
 public function previewReverse(ExpenseReversalRequest $r,Expense $expense,ExpenseService $s){return ApiResponse::success($s->previewReversal($r->user(),$expense,$r->validated()));}
 public function store(ExpenseRequest $r,ExpenseService $s){$data=$s->record($r->user(),$r->validated());return ApiResponse::success($data,$data['replayed']?200:201);}
 public function reverse(ExpenseReversalRequest $r,Expense $expense,ExpenseService $s){return ApiResponse::success($s->reverse($r->user(),$expense,$r->validated()));}
 public function preview(ExpensePaymentRequest $r,Expense $expense,ExpenseService $s){abort_if($r->has('review_hash')||$r->has('approval_token'),422);return ApiResponse::success($s->previewPayment($r->user(),$expense,$r->validated()));}
 public function pay(ExpensePaymentRequest $r,Expense $expense,ExpenseService $s){$r->validate(['review_hash'=>['required','string','regex:/^[a-f0-9]{64}$/D']]);return ApiResponse::success($s->settle($r->user(),$expense,$r->validated()));}
}
