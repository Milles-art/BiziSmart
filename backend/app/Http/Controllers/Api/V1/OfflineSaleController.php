<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Offline\{OfflineReconcileRequest, OfflineSyncRequest};
use App\Http\Responses\ApiResponse;
use App\Models\OfflineOperation;
use App\Services\OfflineSaleService;
use Illuminate\Http\Request;

final class OfflineSaleController {
    public function __construct(private OfflineSaleService $offline) {}
    public function issue(Request $r){return ApiResponse::success($this->offline->issue($r->user()),201);}
    public function sync(OfflineSyncRequest $r){$result=$this->offline->sync($r->validated(),$r->header('X-Offline-Signature',''));return ApiResponse::success($result,$result['replayed']?200:201);}
    public function pending(Request $r){return ApiResponse::success($this->offline->pending($r->user()));}
    public function conflicts(Request $r){$filters=$r->validate(['page'=>['sometimes','integer','min:1'],'per_page'=>['sometimes','integer','min:1','max:50']]);$result=$this->offline->conflicts($r->user(),$filters);return ApiResponse::success(['sales'=>$result['sales']],meta:$result['meta']);}
    public function preview(OfflineReconcileRequest $r,OfflineOperation $offlineOperation){return ApiResponse::success($this->offline->reviewReconciliation($r->user(),$offlineOperation,$r->validated()));}
    public function reconcile(OfflineReconcileRequest $r,OfflineOperation $offlineOperation){return ApiResponse::success($this->offline->reconcile($r->user(),$offlineOperation,$r->validated()));}
}
