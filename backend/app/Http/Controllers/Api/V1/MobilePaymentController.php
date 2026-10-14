<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Payments\BeginPaymentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\MobilePayment;
use App\Services\MobilePaymentService;
use Illuminate\Http\Request;
class MobilePaymentController {
    public function __construct(private MobilePaymentService $payments){}
    public function begin(BeginPaymentRequest $r){$input=$r->validated();$key=$input['idempotency_key'];unset($input['idempotency_key']);return ApiResponse::success($this->payments->begin($r->user(),$input,$key));}
    public function status(Request $r,MobilePayment $payment){return ApiResponse::success($this->payments->status($r->user(),$payment));}
    public function complete(Request $r,MobilePayment $payment){$input=$r->validate(['approval_token'=>['sometimes','string','regex:/^[a-f0-9]{64}$/D']]);return ApiResponse::success($this->payments->complete($r->user(),$payment,$input['approval_token']??null));}
    public function review(Request $r,MobilePayment $payment){return ApiResponse::success($this->payments->checkoutReview($r->user(),$payment));}
    public function mine(Request $r){return ApiResponse::success($this->payments->mine($r->user()));}
    public function index(Request $r){return ApiResponse::success($this->payments->ownerList($r->user()));}
}
