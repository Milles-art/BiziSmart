<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Sales\HoldRequest;
use App\Http\Responses\ApiResponse;
use App\Services\HoldService;
use Illuminate\Http\Request;
class HoldController {
    public function __construct(private HoldService $holds) {}
    public function index(Request $r) {
        $input=$r->validate(['branch_id'=>['required','string','regex:/^br_[0-9A-HJKMNP-TV-Z]{26}$/D']]);
        return ApiResponse::success($this->holds->list($r->user(),$input['branch_id']));
    }
    public function store(HoldRequest $r) {
        $input=$r->validated(); $key=$input['idempotency_key']; unset($input['idempotency_key']);
        $result=$this->holds->save($r->user(),$input,$key);
        return ApiResponse::success($result,$result['replayed']?200:201);
    }
    public function resume(HoldRequest $r,string $hold) { return ApiResponse::success($this->holds->claim($r->user(),$hold,'resume',$r->validated('idempotency_key'))); }
    public function destroy(HoldRequest $r,string $hold) { return ApiResponse::success($this->holds->claim($r->user(),$hold,'delete',$r->validated('idempotency_key'))); }
}
