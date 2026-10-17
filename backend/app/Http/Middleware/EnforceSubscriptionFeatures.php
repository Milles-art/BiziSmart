<?php
namespace App\Http\Middleware;
use App\Exceptions\ApiException;
use App\Services\PlanFeatureService;
use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
class EnforceSubscriptionFeatures {
    public function handle(Request $request,Closure $next){
        // Sign-in and a read-only status message stay reachable; operational tokens are gated.
        if($request->is('api/v1/auth/*','api/v1/subscription-status'))return $next($request);
        if(!app(SubscriptionService::class)->active())throw new ApiException('SUBSCRIPTION_INACTIVE','This shop subscription is inactive. Ask your owner to contact BiziSmart.',403);
        $path=substr($request->path(),strlen('api/v1/'));
        // An integration alias adds API entitlement; it never replaces the underlying module entitlement.
        if($path==='enterprise'||str_starts_with($path,'enterprise/')){
            if(!app(PlanFeatureService::class)->active('api_access'))throw new ApiException('FEATURE_DISABLED','Enterprise API access is disabled for your shop plan.',403);
            $path=substr($path,strlen('enterprise/'));
        }
        if(str_starts_with($path,'incentives/configuration')){
            $flags=app(PlanFeatureService::class)->features();if(!$flags['loyalty']&&!$flags['commissions'])throw new ApiException('FEATURE_DISABLED','Incentives are disabled for this shop.',403);
            return $next($request);
        }
        $mapping=['reports'=>'reports','stock/losses'=>'loss_prevention','stock/counts'=>'loss_prevention','loss-alerts'=>'loss_prevention','suppliers'=>'suppliers','supplier-receiving'=>'suppliers','supplier-price-comparisons'=>'suppliers','banking'=>'banking','pricing'=>'promotions','incentives/commissions'=>'commissions','incentives/leaderboard'=>'commissions','incentives'=>'loyalty','loyalty-cards'=>'loyalty','scales'=>'scales','messaging'=>'messaging','departments'=>'departments','stock'=>'inventory','offline'=>'offline','enterprise'=>'api_access'];
        foreach($mapping as $prefix=>$feature)if($path===$prefix||str_starts_with($path,$prefix.'/')){if(!app(PlanFeatureService::class)->active($feature))throw new ApiException('FEATURE_DISABLED','This feature is disabled for your shop plan.',403);break;}
        if(preg_match('#^customers/[^/]+/loyalty#',$path)&&!app(PlanFeatureService::class)->active('loyalty'))throw new ApiException('FEATURE_DISABLED','Loyalty is disabled for this shop.',403);
        if(preg_match('#^products/(?:import-stock|[^/]+/(?:batches|movements))#',$path)&&!app(PlanFeatureService::class)->active('inventory'))throw new ApiException('FEATURE_DISABLED','Inventory is disabled for this shop.',403);
        return $next($request);
    }
}
