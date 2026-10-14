<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Finance\ReportRequest;
use App\Http\Responses\ApiResponse;
use App\Services\ReportService;
class ReportController {
 public function summary(ReportRequest $r,ReportService $s){try{return ApiResponse::success($s->summary($r->validated()));}catch(\OverflowException){throw new \App\Exceptions\ApiException('REPORT_TOO_LARGE','Choose a narrower report period; its exact totals exceed the supported range.',422);}}
 public function sales(ReportRequest $r,ReportService $s){$data=$s->sales($r->validated());return ApiResponse::success(['sales'=>$data['rows']],meta:$data['meta']);}
 public function stock(ReportRequest $r,ReportService $s){$data=$s->stock($r->validated());return ApiResponse::success(['stock'=>$data['rows'],'as_of'=>$data['as_of']],meta:$data['meta']);}
}
