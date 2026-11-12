<?php

use App\Http\Controllers\Commerce\CommerceController;
use Illuminate\Support\Facades\Route;

/*
| Commerce-API v1: Anbindung des B2B-WooCommerce-Shops (Präfix
| /api/commerce/v1, siehe bootstrap/app.php). Nur signierte Server-zu-
| Server-Aufrufe. Doku: docs/commerce/README.md.
*/

Route::get('/catalog', [CommerceController::class, 'catalog']);

Route::post('/accounts', [CommerceController::class, 'registerAccount']);
Route::get('/accounts/{customerId}', [CommerceController::class, 'showAccount']);
Route::patch('/accounts/{customerId}', [CommerceController::class, 'updateAccount']);
Route::post('/accounts/{customerId}/link', [CommerceController::class, 'linkAccount']);
Route::post('/accounts/{customerId}/deactivate', [CommerceController::class, 'deactivateAccount']);

Route::post('/orders/lines', [CommerceController::class, 'deliver']);
Route::get('/orders/{orderId}/lines/{lineId}', [CommerceController::class, 'showOrderLine']);

Route::get('/batches/{batchId}', [CommerceController::class, 'showBatch'])->whereUuid('batchId');
Route::post('/batches/{batchId}/void', [CommerceController::class, 'voidBatch'])->whereUuid('batchId');
