<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\StaffOperationsController;
// Require in api.php's authenticated staff.session/no-store group. Services refresh session and enforce self/owner scope.
Route::get('/staff-operations',[StaffOperationsController::class,'index']);
Route::post('/staff-operations/{action}',[StaffOperationsController::class,'commit'])->whereIn('action',['clock_in','clock_out','correction','preferences','read_notification']);
