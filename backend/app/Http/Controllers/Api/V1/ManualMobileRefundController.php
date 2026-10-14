<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Payments\ManualRefundRequest;
use App\Http\Responses\ApiResponse;
use App\Models\MobilePayment;
use App\Services\ManualMobileRefundService;
class ManualMobileRefundController {
 public function preview(ManualRefundRequest $r,MobilePayment $payment,ManualMobileRefundService $service){return ApiResponse::success($service->preview($r->user(),$payment,$r->validated()));}
 public function store(ManualRefundRequest $r,MobilePayment $payment,ManualMobileRefundService $service){return ApiResponse::success($service->complete($r->user(),$payment,$r->validated()));}
}
