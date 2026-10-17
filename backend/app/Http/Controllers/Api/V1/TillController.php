<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Tills\{TillOperationRequest,ListTillsRequest};
use App\Http\Resources\TillResource;
use App\Http\Responses\ApiResponse;
use App\Services\TillService;
use Illuminate\Http\Request;
class TillController {
    public function __construct(private TillService $tills) {}
    public function current(Request $r) { return ApiResponse::success($this->tills->current($r->user())); }
    public function index(ListTillsRequest $r) {
        $result=$this->tills->overview($r->user(),$r->validated())->paginate($r->integer('per_page',10));
        return ApiResponse::success(['tills'=>TillResource::collection($result)->resolve()],200,['page'=>$result->currentPage(),'per_page'=>$result->perPage(),'total'=>$result->total(),'owner_review_count'=>$this->tills->ownerReviewCount($r->user())]);
    }
    public function open(TillOperationRequest $r) { return $this->respond($this->tills->open($r->user(),$r->validated())); }
    public function paidOut(TillOperationRequest $r) { return $this->respond($this->tills->paidOut($r->user(),$r->validated())); }
    public function close(TillOperationRequest $r) { return $this->respond($this->tills->close($r->user(),$r->validated())); }
    private function respond(array $result) { return ApiResponse::success($result,$result['replayed'] ? 200 : 201); }
}
