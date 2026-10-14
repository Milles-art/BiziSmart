<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Requests\Customers\{CustomerRequest,CollectDebtRequest};
use App\Http\Resources\CustomerResource;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\{CustomerService,DebtService};
use Illuminate\Http\Request;
class CustomerController
{
    public function __construct(private CustomerService $customers, private DebtService $debts) {}
    public function index(CustomerRequest $r) { $result = $this->customers->list($r->validated()); return ApiResponse::success(['customers'=>CustomerResource::collection($result['customers'])->resolve()],meta:$result['meta']); }
    public function show(Request $r, Customer $customer) { return ApiResponse::success((new CustomerResource($customer))->resolve()); }
    public function store(CustomerRequest $r) { return ApiResponse::success((new CustomerResource($this->customers->save($r->user(),$r->validated())))->resolve(),201); }
    public function update(CustomerRequest $r, Customer $customer) { return ApiResponse::success((new CustomerResource($this->customers->save($r->user(),$r->validated(),$customer)))->resolve()); }
    public function debt(Request $r, Customer $customer) { return ApiResponse::success($this->debts->overview($customer)); }
    public function preview(CollectDebtRequest $r, Customer $customer) { return ApiResponse::success($this->debts->preview($r->user(),$customer,$r->validated())); }
    public function collect(CollectDebtRequest $r, Customer $customer)
    {
        $input = $r->validated(); $key = $input['idempotency_key']; unset($input['idempotency_key']);
        $result = $this->debts->collect($r->user(),$customer,$input,$key); $p = $result['payment'];
        return ApiResponse::success(['id'=>$p->publicId(),'customer_id'=>$customer->publicId(),'amount'=>$p->amount,'method'=>$p->method,
            'allocations'=>$p->allocations,'created_at'=>$p->created_at->toISOString(),'replayed'=>$result['replayed']],$result['replayed'] ? 200 : 201);
    }
}
