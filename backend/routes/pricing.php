<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\PricingController;
Route::prefix('pricing')->middleware('permission:manage_products')->group(function(){
 Route::get('rules',[PricingController::class,'index']);
 Route::post('rules/preview',[PricingController::class,'preview']);
 Route::post('rules',[PricingController::class,'store']);
 Route::post('rules/{rule}/cancel/preview',[PricingController::class,'cancelPreview']);
 Route::post('rules/{rule}/cancel',[PricingController::class,'cancel']);
});
