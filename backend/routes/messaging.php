<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\MessagingController;
Route::get('/messaging',[MessagingController::class,'index'])->middleware('owner');
Route::get('/customers/{customer}/message-consent',[MessagingController::class,'consent'])->middleware('permission:manage_customers');
Route::post('/messaging/{action}',[MessagingController::class,'commit'])->whereIn('action',['consent','receipt','debt_reminder','automation'])->middleware('permission:manage_customers');
