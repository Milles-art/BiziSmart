<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Finance\DepartmentTillReportRequest;
use App\Http\Responses\ApiResponse;
use App\Services\DepartmentTillReportService;
class DepartmentTillReportController {
    public function departments(DepartmentTillReportRequest $request,DepartmentTillReportService $service){return $this->result(fn()=>$service->departments($request->validated()));}
    public function tills(DepartmentTillReportRequest $request,DepartmentTillReportService $service){return $this->result(fn()=>$service->tills($request->validated()));}
    private function result(callable $read){try{$data=$read();$meta=$data['meta']??null;unset($data['meta']);return ApiResponse::success($data,meta:$meta);}catch(\OverflowException){throw new \App\Exceptions\ApiException('REPORT_TOO_LARGE','Choose a narrower report period; its totals exceed the supported range.',422);}}
}
