<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\IncentiveController;
Route::get('customers/{customer}/loyalty',[IncentiveController::class,'loyalty'])->middleware('permission:manage_customers');
Route::prefix('incentives')->middleware('permission:view_reports')->group(function(){Route::get('configuration',[IncentiveController::class,'configuration']);Route::post('configuration/preview',[IncentiveController::class,'previewConfiguration']);Route::post('configuration',[IncentiveController::class,'configure']);Route::get('commissions',[IncentiveController::class,'commissions']);Route::post('commission-payouts/preview',[IncentiveController::class,'previewPayout']);Route::post('commission-payouts',[IncentiveController::class,'payout']);Route::get('forecast',[IncentiveController::class,'forecast']);});
