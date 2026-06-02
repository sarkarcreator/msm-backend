<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', DashboardController::class);
    Route::post('/sync/push', [SyncController::class, 'push']);
    Route::get('/sync/pull', [SyncController::class, 'pull']);

    foreach ([
        'products', 'categories', 'brands', 'customers', 'customer-ledgers',
        'suppliers', 'supplier-ledgers', 'sales', 'sale-items', 'purchases',
        'purchase-items', 'expenses', 'payments', 'cashbook', 'repairs',
        'repair-updates', 'inventory-transactions', 'users', 'roles',
        'permissions', 'settings', 'notifications', 'manual-repair-receipts', 'licenses',
        'audit-logs'
    ] as $resource) {
        Route::apiResource($resource, ResourceController::class)->parameters([$resource => 'id']);
    }
});
