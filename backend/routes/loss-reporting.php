<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\DepartmentTillReportController;
Route::middleware(['owner','permission:view_reports'])->group(function(){
    Route::get('/reports/departments',[DepartmentTillReportController::class,'departments']);
    Route::get('/reports/tills',[DepartmentTillReportController::class,'tills']);
    Route::get('/reports/losses',[\App\Http\Controllers\Api\V1\StockLossCountController::class,'report']);
    Route::get('/loss-alerts',[\App\Http\Controllers\Api\V1\LossAlertController::class,'index']);
    Route::put('/loss-alerts/configuration',[\App\Http\Controllers\Api\V1\LossAlertController::class,'configure']);
    Route::post('/loss-alerts/refresh',[\App\Http\Controllers\Api\V1\LossAlertController::class,'refresh']);
    Route::post('/loss-alerts/{alert}/acknowledge',[\App\Http\Controllers\Api\V1\LossAlertController::class,'acknowledge']);
});
Route::middleware('permission:adjust_stock')->group(function(){foreach(['losses','counts'] as $path){Route::post('/stock/'.$path.'/preview',[\App\Http\Controllers\Api\V1\StockLossCountController::class,'preview']);Route::post('/stock/'.$path,[\App\Http\Controllers\Api\V1\StockLossCountController::class,'store']);}});
