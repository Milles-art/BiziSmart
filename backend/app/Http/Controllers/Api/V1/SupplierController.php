<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Suppliers\SupplierActionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\SupplierService;
use Illuminate\Http\Request;
class SupplierController {
    public function __construct(private SupplierService $suppliers){}
    public function index(Request $r){return ApiResponse::success($this->suppliers->index($r->user()));}
    public function receiving(Request $r){return ApiResponse::success($this->suppliers->receiving($r->user()));}
    public function preview(SupplierActionRequest $r,string $action){return ApiResponse::success($this->suppliers->preview($r->user(),$action,$r->validated()));}
    public function commit(SupplierActionRequest $r,string $action){$input=$r->validated();$key=$input['idempotency_key'];unset($input['idempotency_key']);return ApiResponse::success($this->suppliers->commit($r->user(),$action,$input,$key));}
}
