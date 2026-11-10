<?php

use App\Http\Controllers\Api\V1\PurchaseSuggestionController;
use Illuminate\Support\Facades\Route;

// Included from the authenticated staff.session/no-store API group.
Route::get('/suppliers/{supplier}/purchase-suggestions', PurchaseSuggestionController::class)->middleware('owner');
