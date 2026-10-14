<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\BankReconciliationController;
Route::middleware('owner')->group(function(){Route::get('/banking',[BankReconciliationController::class,'index']);Route::get('/supplier-price-comparisons',[BankReconciliationController::class,'prices']);Route::post('/banking/{action}',[BankReconciliationController::class,'commit'])->whereIn('action',['statement','match','schedule','cancel']);});
