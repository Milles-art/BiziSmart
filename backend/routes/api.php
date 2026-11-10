<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/offline.php';
Route::get('/health', HealthController::class)->name('health');
Route::get('/ready', \App\Http\Controllers\Api\V1\ReadinessController::class)->middleware('throttle:60,1')->name('ready');
Route::match(['GET','POST'],'/messaging/whatsapp/webhook',\App\Http\Controllers\Api\V1\WhatsAppWebhookController::class)->middleware('throttle:120,1')->name('messaging.whatsapp.webhook');
Route::post('/payments/selcom/callback', \App\Http\Controllers\Api\V1\SelcomCallbackController::class)->name('payments.selcom.callback');

Route::middleware('auth.no_store')->group(function () {
    $emailAuth = \App\Http\Controllers\Api\V1\EmailAuthController::class;
    Route::post('/auth/email/login', [$emailAuth, 'login'])->middleware('throttle:staff-login');
    Route::post('/auth/email/setup', [$emailAuth, 'setup'])->middleware('throttle:auth-step');
    Route::post('/auth/email/forgot-password', [$emailAuth, 'forgot'])->middleware('throttle:staff-login');
    Route::post('/auth/email/reset-password', [$emailAuth, 'reset'])->middleware('throttle:auth-step');
    Route::post('/auth/email/activate', [$emailAuth, 'activate'])->middleware('throttle:auth-step');
    Route::get('/auth/email/register/status', [$emailAuth, 'registerStatus'])->middleware('throttle:30,1')->name('auth.register.status');
    Route::post('/auth/email/register', [$emailAuth, 'register'])->middleware('throttle:5,1')->name('auth.register');
    $auth = \App\Http\Controllers\Api\V1\AuthController::class;
    Route::post('/auth/login', [$auth, 'login'])->middleware('throttle:staff-login')->name('auth.login');
    Route::post('/auth/owner/setup', [$auth, 'setup'])->middleware('throttle:auth-step');
    Route::post('/auth/totp/verify', [$auth, 'verify'])->middleware('throttle:auth-step')->name('auth.totp.verify');
    Route::post('/auth/pin/change', [$auth, 'changePin'])->middleware('throttle:auth-step')->name('auth.pin.change');
    Route::middleware(['auth:sanctum'])->group(function () {
        require __DIR__.'/enterprise.php';
    });
    Route::middleware(['auth:sanctum', 'staff.session'])->group(function () use ($auth) {
        Route::post('/auth/email/enrollment', [\App\Http\Controllers\Api\V1\EmailAuthController::class, 'enroll'])->middleware('throttle:auth-step');
        Route::post('/auth/email/activation-link', [\App\Http\Controllers\Api\V1\EmailAuthController::class, 'activationLink'])->middleware(['owner', 'throttle:auth-step']);
        require __DIR__.'/suppliers.php';
        require __DIR__.'/purchase-assistance.php';
        require __DIR__.'/settings.php';
        require __DIR__.'/pricing.php';
        require __DIR__.'/finance.php';
        require __DIR__.'/staff-operations.php';
        require __DIR__.'/incentives.php';
        require __DIR__.'/banking.php';
        require __DIR__.'/loss-reporting.php';
        require __DIR__.'/display_loyalty.php';
        require __DIR__.'/messaging.php';
        Route::get('/subscription-status', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'status']);
        Route::get('/me', [$auth, 'me']);
        Route::get('/me/features', [$auth, 'features']);
        Route::post('/auth/logout', [$auth, 'logout']);
        Route::post('/auth/token/rotate', [$auth, 'rotate']);
        Route::post('/auth/supervisor-check', [$auth, 'supervisor'])->middleware('throttle:staff-login');
        Route::middleware('permission:sell')->group(function () {
            Route::get('/payments/capabilities', \App\Http\Controllers\Api\V1\PaymentCapabilitiesController::class)->name('payments.capabilities');
            $mobile = \App\Http\Controllers\Api\V1\MobilePaymentController::class;
            Route::get('/payments/mine',[$mobile,'mine']);
            Route::post('/payments/selcom',[$mobile,'begin'])->middleware('throttle:60,1');
            Route::get('/payments/{payment}',[$mobile,'status']);
            Route::post('/payments/{payment}/complete',[$mobile,'complete']);
            Route::post('/payments/{payment}/review',[$mobile,'review']);
            $returns = \App\Http\Controllers\Api\V1\ReturnController::class;
            Route::get('/returns/receipt', [$returns,'receipt']);
            Route::post('/returns/preview', [$returns,'preview'])->name('returns.preview');
            Route::post('/returns', [$returns,'store'])->name('returns.complete');
            require __DIR__.'/holds.php';
            $sales = \App\Http\Controllers\Api\V1\SaleController::class;
            Route::post('/sales/preview', [$sales, 'preview'])->name('sales.preview');
            Route::post('/sales', [$sales, 'store'])->name('sales.complete');
            Route::get('/sales/{sale}', [$sales, 'show']);
        });
        Route::middleware('permission:manage_till')->group(function () {
            $tills = \App\Http\Controllers\Api\V1\TillController::class;
            Route::get('/till-sessions', [$tills, 'index']);
            Route::get('/till-sessions/current', [$tills, 'current']);
            Route::post('/till-sessions/open', [$tills, 'open'])->name('tills.open');
            Route::post('/till-sessions/current/paid-out', [$tills, 'paidOut'])->name('tills.paid_out');
            Route::post('/till-sessions/current/close', [$tills, 'close'])->name('tills.close');
        });
        $customers = \App\Http\Controllers\Api\V1\CustomerController::class;
        Route::middleware('permission:manage_customers')->group(function () use ($customers) {
            Route::get('/customers', [$customers,'index']);
            Route::post('/customers', [$customers,'store']);
            Route::get('/customers/{customer}', [$customers,'show']);
            Route::put('/customers/{customer}', [$customers,'update']);
            Route::get('/customers/{customer}/debt', [$customers,'debt']);
            Route::get('/customers/{customer}/statement', [$customers,'statement']);
            Route::get('/customers/{customer}/statement/export', [$customers,'statementExport']);
            Route::post('/customers/{customer}/payments/preview', [$customers,'preview'])->name('debts.preview');
            Route::post('/customers/{customer}/payments', [$customers,'collect'])->name('debts.collect');
        });
        $products = \App\Http\Controllers\Api\V1\ProductController::class;
        $departments = \App\Http\Controllers\Api\V1\DepartmentController::class;
        Route::get('/departments', [$departments, 'index']);
        $imports=\App\Http\Controllers\Api\V1\CatalogueImportController::class;
        Route::post('/products/import-stock/preview',[$imports,'preview'])->middleware('permission:receive_stock');
        Route::post('/products/import-stock',[$imports,'commit'])->middleware('permission:receive_stock');
        Route::get('/products/export',\App\Http\Controllers\Api\V1\CatalogueExportController::class)->middleware('permission:manage_products');
        $images=\App\Http\Controllers\Api\V1\ProductImageController::class;
        Route::get('/products/image-capabilities',[$images,'capabilities']);
        Route::get('/products/{product}/image/{revision}',[$images,'show']);
        Route::get('/products', [$products, 'index']);
        Route::get('/products/{product}', [$products, 'show']);
        $packagings = \App\Http\Controllers\Api\V1\PackagingController::class;
        Route::get('/products/{product}/packagings', [$packagings, 'index']);
        Route::post('/stock/adjust/preview', [\App\Http\Controllers\Api\V1\StockAdjustmentController::class, 'preview'])->middleware('permission:adjust_stock')->name('stock.adjust.preview');
        Route::post('/stock/adjust', [\App\Http\Controllers\Api\V1\StockAdjustmentController::class, 'store'])->middleware('permission:adjust_stock')->name('stock.adjust');
        $receiving = \App\Http\Controllers\Api\V1\StockReceivingController::class;
        $alerts = \App\Http\Controllers\Api\V1\StockAlertsController::class;
        Route::get('/stock/reorder-alerts', [$alerts, 'reorder'])->middleware('permission:receive_stock');
        Route::get('/stock/expiry-alerts', [$alerts, 'expiry'])->middleware('permission:receive_stock');
        Route::middleware('permission:receive_stock')->group(function () use ($receiving) {
            Route::get('/products/{product}/movements', [\App\Http\Controllers\Api\V1\StockMovementController::class, 'index']);
            Route::get('/products/{product}/batches', [$receiving, 'index']);
            Route::post('/products/{product}/batches', [$receiving, 'store']);
        });
        Route::middleware('permission:manage_products')->group(function () use ($products, $packagings, $images) {
            Route::post('/products/{product}/packagings', [$packagings, 'store']);
            Route::put('/products/{product}/packagings/{packaging}', [$packagings, 'update']);
            Route::delete('/products/{product}/packagings/{packaging}', [$packagings, 'destroy']);
            Route::post('/products/{product}/image',[$images,'store']);
            Route::delete('/products/{product}/image',[$images,'destroy']);
            $prices=\App\Http\Controllers\Api\V1\ProductPriceController::class;
            Route::get('/products/{product}/price-history',[$prices,'history']);
            Route::get('/products/{product}/shelf-labels',[$prices,'labels']);
            Route::post('/products', [$products, 'store']);
            Route::put('/products/{product}', [$products, 'update']);
            Route::delete('/products/{product}', [$products, 'destroy']);
        });
        Route::middleware('permission:manage_products')->group(function () use ($departments) {
            Route::post('/departments', [$departments, 'store']);
            Route::put('/departments/{department}', [$departments, 'update']);
            Route::delete('/departments/{department}', [$departments, 'destroy']);
        });
        Route::middleware('owner')->group(function () use ($auth) {
            $manualRefund=\App\Http\Controllers\Api\V1\ManualMobileRefundController::class;
            Route::post('/payments/{payment}/manual-refund/preview',[$manualRefund,'preview']);
            Route::post('/payments/{payment}/manual-refund',[$manualRefund,'store'])->name('payments.manual_refund');
            Route::get('/payments', [\App\Http\Controllers\Api\V1\MobilePaymentController::class,'index']);
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
