<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['data' => ['status' => 'ok', 'service' => 'WalangBrownout API', 'version' => 'v1']]));

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{identifier}', [ProductController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::patch('/orders/{order}/status', [OrderController::class, 'updateStatus']);

        Route::get('/warehouse/batches', [WarehouseController::class, 'batches']);
        Route::post('/warehouse/receipts', [WarehouseController::class, 'receive']);
        Route::post('/warehouse/picks', [WarehouseController::class, 'pick']);
        Route::post('/warehouse/counts', [WarehouseController::class, 'count']);
        Route::get('/warehouse/discrepancies', [WarehouseController::class, 'discrepancies']);
        Route::post('/warehouse/discrepancies/{discrepancy}/resolve', [WarehouseController::class, 'resolveDiscrepancy']);

        Route::get('/purchasing/reorder-alerts', [PurchaseOrderController::class, 'alerts']);
        Route::get('/purchasing/orders', [PurchaseOrderController::class, 'index']);
        Route::post('/purchasing/orders', [PurchaseOrderController::class, 'store']);
        Route::post('/purchasing/orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);

        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/inventory-movements', [ReportController::class, 'movements']);
    });
});
