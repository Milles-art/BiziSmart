<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\StaffOperations\StaffOperationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\StaffOperationsService;
use Illuminate\Http\Request;
class StaffOperationsController {
    public function __construct(private StaffOperationsService $operations){}
    public function index(Request $r){return ApiResponse::success($this->operations->overview($r->user()));}
    public function commit(StaffOperationRequest $r,string $action){$input=$r->validated();$key=$input['idempotency_key'];unset($input['idempotency_key']);return ApiResponse::success($this->operations->commit($r->user(),$action,$input,$key));}
}
