<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\HoldController;
// Require inside api.php's authenticated, approved-device permission:sell group.
Route::get('/held-carts',[HoldController::class,'index']);
Route::post('/held-carts',[HoldController::class,'store'])->name('holds.save');
Route::post('/held-carts/{hold}/resume',[HoldController::class,'resume'])->name('holds.resume');
Route::delete('/held-carts/{hold}',[HoldController::class,'destroy'])->name('holds.delete');
