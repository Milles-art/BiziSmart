<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\{ExpenseController,ReportController,CashDropController};
Route::middleware(['owner','permission:manage_settings'])->group(function(){
 Route::get('/expenses/attachment-capabilities',[\App\Http\Controllers\Api\V1\ExpenseAttachmentController::class,'capabilities']);
 Route::get('/expenses/{expense}/attachments',[\App\Http\Controllers\Api\V1\ExpenseAttachmentController::class,'index']);
 Route::post('/expenses/{expense}/attachments',[\App\Http\Controllers\Api\V1\ExpenseAttachmentController::class,'store']);
 Route::get('/expenses/{expense}/attachments/{attachment}',[\App\Http\Controllers\Api\V1\ExpenseAttachmentController::class,'show']);
 Route::delete('/expenses/{expense}/attachments/{attachment}',[\App\Http\Controllers\Api\V1\ExpenseAttachmentController::class,'remove']);
 Route::post('/expenses/preview',[ExpenseController::class,'previewRecord']);Route::get('/expenses',[ExpenseController::class,'index']);Route::post('/expenses',[ExpenseController::class,'store']);
 Route::post('/expenses/{expense}/reverse/preview',[ExpenseController::class,'previewReverse']);Route::post('/expenses/{expense}/reverse',[ExpenseController::class,'reverse']);Route::post('/expenses/{expense}/payments/preview',[ExpenseController::class,'preview']);Route::post('/expenses/{expense}/payments',[ExpenseController::class,'pay']);
});
Route::middleware(['owner','permission:view_reports'])->group(function(){Route::get('/reports/summary',[ReportController::class,'summary']);Route::get('/reports/sales',[ReportController::class,'sales']);Route::get('/reports/stock',[ReportController::class,'stock']);});
Route::middleware('permission:manage_till')->group(function(){Route::get('/cash-drops/receivers',[CashDropController::class,'receivers']);Route::get('/cash-drops',[CashDropController::class,'index']);Route::post('/cash-drops/preview',[CashDropController::class,'preview']);Route::post('/cash-drops',[CashDropController::class,'store']);Route::post('/cash-drops/{drop}/acknowledge',[CashDropController::class,'acknowledge']);Route::post('/cash-drops/{drop}/reverse',[CashDropController::class,'reverse']);});
