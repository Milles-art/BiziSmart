<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Products\LossCountRequest;
use App\Http\Requests\Finance\LossReportRequest;
use App\Http\Resources\StockMovementResource;
use App\Http\Responses\ApiResponse;
use App\Services\StockService;
class StockLossCountController {
    public function preview(LossCountRequest $request,StockService $service){return ApiResponse::success($service->previewLossCount($request->user(),$request->validated(),str_contains($request->path(),'/counts')));}
    public function store(LossCountRequest $request,StockService $service){$result=$service->commitLossCount($request->user(),$request->validated(),str_contains($request->path(),'/counts'));return ApiResponse::success(['movement'=>(new StockMovementResource($result['movement']))->resolve(),'loss'=>$result['movement']->receipt,'replayed'=>$result['replayed']],$result['replayed']?200:201);}
    public function report(LossReportRequest $request,StockService $service){try{$data=$service->losses($request->validated());$meta=$data['meta'];unset($data['meta']);return ApiResponse::success($data,meta:$meta);}catch(\OverflowException){throw new \App\Exceptions\ApiException('REPORT_TOO_LARGE','Choose a narrower loss report period.',422);}}
}
