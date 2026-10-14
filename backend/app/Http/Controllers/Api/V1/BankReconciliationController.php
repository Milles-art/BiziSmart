<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Finance\BankActionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\BankReconciliationService;
use Illuminate\Http\Request;
class BankReconciliationController {
 public function __construct(private BankReconciliationService $service){}
 public function index(Request $r){return ApiResponse::success($this->service->index($r->user()));}
 public function prices(Request $r){return ApiResponse::success($this->service->prices($r->user()));}
 public function commit(BankActionRequest $r,string $action){return ApiResponse::success($this->service->commit($r->user(),$action,$r->validated()));}
}
