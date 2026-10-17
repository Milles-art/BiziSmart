<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\{EnterpriseController,ProductController,PackagingController,StockReceivingController,StockMovementController,StockAlertsController,ReportController,DepartmentController,TillController};
// Owner-managed headless credentials (interactive staff session only).
Route::middleware(['staff.session','owner'])->group(function () {
    $tokens = EnterpriseController::class;
    Route::get('/enterprise/tokens', [$tokens,'index']);
    Route::post('/enterprise/tokens', [$tokens,'store']);
    Route::delete('/enterprise/tokens/{token}', [$tokens,'destroy']);
});
// Headless integration reads: strict enterprise:read ability, owner role, per-key throttle. No mutations.
Route::middleware(['enterprise.token','owner','throttle:enterprise'])->group(function () {
    Route::get('/enterprise/products', [ProductController::class,'index']);
    Route::get('/enterprise/products/{product}', [ProductController::class,'show']);
    Route::get('/enterprise/products/{product}/packagings', [PackagingController::class,'index']);
    Route::get('/enterprise/products/{product}/batches', [StockReceivingController::class,'index']);
    Route::get('/enterprise/products/{product}/movements', [StockMovementController::class,'index']);
    Route::get('/enterprise/stock/reorder-alerts', [StockAlertsController::class,'reorder']);
    Route::get('/enterprise/stock/expiry-alerts', [StockAlertsController::class,'expiry']);
    Route::get('/enterprise/reports/summary', [ReportController::class,'summary']);
    Route::get('/enterprise/reports/sales', [ReportController::class,'sales']);
    Route::get('/enterprise/reports/stock', [ReportController::class,'stock']);
    Route::get('/enterprise/departments', [DepartmentController::class,'index']);
    Route::get('/enterprise/till-sessions', [TillController::class,'index']);
});
