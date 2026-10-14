<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Responses\ApiResponse;
use App\Models\{Business,MobilePayment};
use App\Payments\{SelcomMerchant,SelcomSignature};
use App\Services\MobilePaymentService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
class SelcomCallbackController {
    public function __invoke(Request $r,TenantContext $context,SelcomMerchant $merchant,SelcomSignature $signature,MobilePaymentService $payments){
        // Read the exact un-normalized signed JSON, never the trimmed input bag or tenant supplied by the caller.
        if(strlen($r->getContent())>16384)return ApiResponse::error('VALIDATION_ERROR','Invalid payment notification.',422);
        try{$body=json_decode($r->getContent(),true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return ApiResponse::error('VALIDATION_ERROR','Invalid payment notification.',422);}
        if(!is_array($body)||!is_string($body['order_id']??null))return ApiResponse::error('VALIDATION_ERROR','Invalid payment notification.',422);
        $p=(new MobilePayment)->resolveRouteBindingQuery(MobilePayment::withoutGlobalScopes(),$body['order_id'])->first();
        if(!$p){\Illuminate\Support\Facades\Log::warning('Selcom notification for an unknown order.');return ApiResponse::success(['received'=>true]);}
        $business=Business::whereKey($p->business_id)->where('is_active',true)->first();
        if(!$business)return ApiResponse::success(['received'=>true]);
        return $context->run($business,function()use($p,$r,$body,$merchant,$signature,$payments,$business){
            $settings=$merchant->configuration($business);$headers=[];
            foreach(['Authorization','Digest-Method','Timestamp','Signed-Fields','Digest'] as $key)$headers[$key]=$r->header($key);
            $notification=$signature->notification($body,$headers,$settings['api_key'],$settings['api_secret'],new \DateTimeImmutable);
            if(!$notification)
                return ApiResponse::error('PAYMENT_SIGNATURE_INVALID','Invalid payment notification signature.',401);
            $payments->notification($p,\App\Services\SupervisorApprovalService::snapshotHash($notification));
            return ApiResponse::success(['received'=>true]);
        });
    }
}
