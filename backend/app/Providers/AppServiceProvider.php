<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Tenancy\TenantContext;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Payments\MobileMoneyGateway::class, \App\Payments\SelcomGateway::class);
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach(config('platform.features') as $feature){
            \Laravel\Pennant\Feature::define($feature,function(string $scope)use($feature){
                if($scope!=='business:'.app(TenantContext::class)->requireId())return false;
                return app(\App\Services\PlanFeatureService::class)->features()[$feature]??false;
            });
        }
        \Illuminate\Support\Facades\RateLimiter::for('staff-login', function (\Illuminate\Http\Request $request) {
            $phone = is_string($request->input('phone')) ? $request->input('phone') : '';
            try { $phone = \App\Support\StaffPhone::normalize($phone); } catch (\InvalidArgumentException) {}
            $key = hash_hmac('sha256', $phone.'|'.$request->ip(), config('app.key'));
            $tenant = app(TenantContext::class)->requireId();
            return [\Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('business:'.$tenant.':login:'.$key),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by('business:'.$tenant.':login-ip:'.hash('sha256', $request->ip() ?? ''))];
        });
        \Illuminate\Support\Facades\RateLimiter::for('auth-step', fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by(app(TenantContext::class)->cacheKey('auth-step:'.hash('sha256', $request->ip() ?? ''))));
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Sanctum::authenticateAccessTokensUsing(function ($token, bool $valid): bool {
            return $valid && $token->tokenable instanceof User
                && $token->tokenable->is_active
                && (! $token->device_id || \App\Models\Device::whereKey($token->device_id)->where('status', 'approved')->exists())
                && (int) $token->business_id === app(TenantContext::class)->id();
        });
    }
}
