<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Business;
use App\Payments\SelcomMerchant;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

final class PaymentCapabilitiesController extends Controller
{
    public function __invoke(TenantContext $context, SelcomMerchant $merchant): JsonResponse
    {
        $business = Business::whereKey($context->requireId())->where('is_active', true)->firstOrFail();
        $configured = $merchant->configured($business);
        // Collection uses the persisted, verified and one-use payment lifecycle.
        return ApiResponse::success(['provider'=>'selcom','configured'=>$configured,'enabled'=>$configured,
            'reason'=>$configured ? 'ready' : 'merchant_setup_required']);
    }
}
