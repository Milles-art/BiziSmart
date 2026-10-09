<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware('auth.no_store')->group(function () {
    $auth = \App\Http\Controllers\Api\V1\AuthController::class;
    Route::post('/auth/login', [$auth, 'login'])->middleware('throttle:staff-login')->name('auth.login');
    Route::post('/auth/owner/setup', [$auth, 'setup'])->middleware('throttle:auth-step');
    Route::post('/auth/totp/verify', [$auth, 'verify'])->middleware('throttle:auth-step')->name('auth.totp.verify');
    Route::post('/auth/pin/change', [$auth, 'changePin'])->middleware('throttle:auth-step')->name('auth.pin.change');
    Route::middleware(['auth:sanctum', 'staff.session'])->group(function () use ($auth) {
        Route::get('/me', [$auth, 'me']);
        Route::get('/me/features', [$auth, 'features']);
        Route::post('/auth/logout', [$auth, 'logout']);
        Route::post('/auth/token/rotate', [$auth, 'rotate']);
        Route::post('/auth/supervisor-check', [$auth, 'supervisor'])->middleware('throttle:staff-login');
        $products = \App\Http\Controllers\Api\V1\ProductController::class;
        Route::get('/products', [$products, 'index']);
        Route::get('/products/{product}', [$products, 'show']);
        $packagings = \App\Http\Controllers\Api\V1\PackagingController::class;
        Route::get('/products/{product}/packagings', [$packagings, 'index']);
        Route::middleware('permission:manage_products')->group(function () use ($products, $packagings) {
            Route::post('/products/{product}/packagings', [$packagings, 'store']);
            Route::put('/products/{product}/packagings/{packaging}', [$packagings, 'update']);
            Route::delete('/products/{product}/packagings/{packaging}', [$packagings, 'destroy']);
            Route::post('/products', [$products, 'store']);
            Route::put('/products/{product}', [$products, 'update']);
            Route::delete('/products/{product}', [$products, 'destroy']);
        });
        Route::middleware('owner')->group(function () use ($auth) {
            $staff = \App\Http\Controllers\Api\V1\StaffController::class;
            Route::get('/users', [$staff, 'index']);
            Route::post('/users', [$staff, 'store']);
            Route::put('/users/{user}', [$staff, 'update']);
            Route::post('/users/{user}/deactivate', [$staff, 'deactivate']);
            Route::post('/auth/pin/reset', [$auth, 'resetPin']);
            $devices = \App\Http\Controllers\Api\V1\DeviceController::class;
            Route::get('/devices', [$devices, 'index']);
            Route::post('/devices/{device}/approve', [$devices, 'approve']);
            Route::post('/devices/{device}/block', [$devices, 'block']);
        });
    });
});
