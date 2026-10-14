<?php
namespace App\Services;
use App\Models\Business;
use App\Tenancy\TenantContext;
class PlanFeatureService {
    public function features():array {
        $shop=Business::findOrFail(app(TenantContext::class)->requireId());
        $configured=array_replace($shop->plan->features??[],$shop->settings['feature_overrides']??[]);
        // Existing locally provisioned clients have no inferred paid tier assignment.
        $flags=[];foreach(config('platform.features') as $key)$flags[$key]=$key==='tra'?false:($configured[$key]??true)===true;
        return $flags;
    }
    public function active(string $feature):bool {
        // Purge Pennant's request cache so an administrator's disable is immediately authoritative.
        $scope='business:'.app(TenantContext::class)->requireId();
        \Laravel\Pennant\Feature::purge($feature);
        return \Laravel\Pennant\Feature::for($scope)->active($feature);
    }
}
