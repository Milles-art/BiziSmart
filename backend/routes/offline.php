<?php
use App\Http\Controllers\Api\V1\OfflineSaleController;
use Illuminate\Support\Facades\Route;

// The sync request authenticates a persisted tenant/device lease and the whole immutable body, never a client business ID.
Route::middleware(['auth.no_store','throttle:120,1'])->group(function(){
    Route::post('/offline/sync',[OfflineSaleController::class,'sync'])->name('offline.sync');
    Route::middleware(['auth:sanctum','staff.session','permission:sell'])->group(function(){
        Route::post('/offline/leases',[OfflineSaleController::class,'issue']);
        Route::middleware('owner')->group(function(){
            Route::get('/offline/quarantine',[OfflineSaleController::class,'pending']);
            Route::get('/offline/conflicts',[OfflineSaleController::class,'conflicts']);
            Route::post('/offline/quarantine/{offlineOperation}/preview',[OfflineSaleController::class,'preview'])->name('offline.reconcile.preview');
            Route::post('/offline/quarantine/{offlineOperation}/reconcile',[OfflineSaleController::class,'reconcile']);
        });
    });
});
