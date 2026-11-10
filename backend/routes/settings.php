<?php

use App\Http\Controllers\Api\V1\BusinessSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/business', [BusinessSettingsController::class, 'show']);
Route::put('/settings/business', [BusinessSettingsController::class, 'update'])->middleware('owner');
