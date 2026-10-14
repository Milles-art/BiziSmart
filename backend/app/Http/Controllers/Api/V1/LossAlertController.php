<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Finance\LossAlertConfigurationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\LossAlert;
use App\Services\LossAlertService;
use Illuminate\Http\Request;
class LossAlertController {
    public function index(Request $request,LossAlertService $service){$input=$request->validate(['page'=>['sometimes','integer','min:1']]);$data=$service->listing($input['page']??1);$meta=$data['meta'];unset($data['meta']);return ApiResponse::success($data,meta:$meta);}
    public function configure(LossAlertConfigurationRequest $request,LossAlertService $service){return ApiResponse::success(['configuration'=>$service->configure($request->user(),$request->validated())]);}
    public function refresh(Request $request,LossAlertService $service){return ApiResponse::success($service->refresh($request->user()));}
    public function acknowledge(Request $request,LossAlert $alert,LossAlertService $service){$service->acknowledge($request->user(),$alert);return ApiResponse::success(['acknowledged'=>true]);}
}
