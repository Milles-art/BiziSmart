<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Sales\ReturnRequest;
use App\Http\Responses\ApiResponse;
use App\Services\ReturnService;
use Illuminate\Http\Request;
class ReturnController {
    public function __construct(private ReturnService $returns) {}
    public function receipt(Request $r) {
        $input=$r->validate(['receipt_number'=>['required','string','max:40']]);
        return ApiResponse::success($this->returns->receipt($r->user(),$input['receipt_number']));
    }
    public function preview(ReturnRequest $r) {return ApiResponse::success($this->returns->preview($r->user(),$r->validated()));}
    public function store(ReturnRequest $r) {
        $input=$r->validated();unset($input['idempotency_key']);$result=$this->returns->complete($r->user(),$input);
        return ApiResponse::success($result,$result['replayed']?200:201);
    }
}
