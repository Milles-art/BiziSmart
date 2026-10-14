<?php
namespace App\Http\Controllers\Api\V1;
use App\Services\MessagingService;
use App\Http\Responses\ApiResponse;
use App\Http\Requests\Messaging\MessagingRequest;
use App\Models\Customer;
use Illuminate\Http\Request;
class MessagingController {
    public function __construct(private MessagingService $service){}
    public function index(Request $request){return ApiResponse::success($this->service->overview($request->user()));}
    public function consent(Customer $customer){return ApiResponse::success($this->service->consent($customer));}
    public function commit(MessagingRequest $request,string $action){$data=$request->validated();$key=$data['operation_id'];unset($data['operation_id']);return ApiResponse::success($this->service->commit($request->user(),$action,$data,$key));}
}
