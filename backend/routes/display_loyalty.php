<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DisplayLoyaltyController;
Route::middleware('permission:manage_customers')->group(function(){Route::post('loyalty-cards/resolve',[DisplayLoyaltyController::class,'resolve'])->middleware('throttle:60,1');Route::get('customers/{customer}/loyalty-card',[DisplayLoyaltyController::class,'card']);Route::post('customers/{customer}/loyalty-card',[DisplayLoyaltyController::class,'issue']);});
Route::get('incentives/leaderboard',[DisplayLoyaltyController::class,'leaderboard'])->middleware(['owner','permission:view_reports']);
Route::get('scales/profile',[\App\Http\Controllers\Api\V1\ScaleLabelController::class,'profile'])->middleware('permission:sell');
Route::post('scales/profile',[\App\Http\Controllers\Api\V1\ScaleLabelController::class,'configure'])->middleware(['owner','permission:manage_settings']);
Route::post('scales/decode',[\App\Http\Controllers\Api\V1\ScaleLabelController::class,'decode'])->middleware('permission:sell');
