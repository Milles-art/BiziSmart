<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Sales\CashSaleRequest;
use App\Http\Resources\SaleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\Request;
class SaleController {
    public function __construct(private SaleService $sales) {}
    public function preview(CashSaleRequest $r) { return ApiResponse::success($this->sales->preview($r->user(), $r->validated())); }
    public function store(CashSaleRequest $r) {
        $input=$r->validated(); $key=$input['idempotency_key']; unset($input['idempotency_key']);
        $result=$this->sales->complete($r->user(), $input, $key);
        return ApiResponse::success([...(new SaleResource($result['sale']))->resolve(), 'replayed'=>$result['replayed']], $result['replayed'] ? 200 : 201);
    }
    public function show(Request $r, Sale $sale) { return ApiResponse::success((new SaleResource($this->sales->read($r->user(), $sale)))->resolve()); }
}
