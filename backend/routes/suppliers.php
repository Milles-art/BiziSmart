<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SupplierController;
// Require in api.php's authenticated staff.session/no-store group.
Route::middleware('owner')->group(function(){
    Route::get('/suppliers',[SupplierController::class,'index']);
});
// Form Request and service both enforce owner-only finances versus authorised receiving.
Route::post('/suppliers/actions/{action}/preview',[SupplierController::class,'preview'])->whereIn('action',['supplier','order','receive','payment','return','credit','apply_credit'])->name('suppliers.preview');
Route::post('/suppliers/actions/{action}',[SupplierController::class,'commit'])->whereIn('action',['supplier','order','receive','payment','return','credit','apply_credit'])->name('suppliers.commit');
Route::middleware('permission:receive_stock')->group(function(){
    Route::get('/supplier-receiving',[SupplierController::class,'receiving']);
});
