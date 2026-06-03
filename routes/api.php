<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LicenseActivationController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/license/activate', [LicenseActivationController::class, 'activate']);
Route::get('/health', fn () => ['status' => 'ok']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', DashboardController::class);
    Route::post('/sync/push', [SyncController::class, 'push']);
    Route::get('/sync/pull', [SyncController::class, 'pull']);
    Route::get('/medicines/search', [MedicineController::class, 'search'])->middleware('throttle:120,1');
    Route::post('/medicines/import', [MedicineController::class, 'import'])->middleware('throttle:10,1');
    Route::get('/medicine-categories', [MedicineController::class, 'categories']);
    Route::get('/medicine-manufacturers', [MedicineController::class, 'manufacturers']);
    Route::apiResource('medicines', MedicineController::class)->parameters(['medicines' => 'medicine']);

    foreach ([
        'products', 'categories', 'brands', 'customers', 'customer-ledgers',
        'suppliers', 'supplier-ledgers', 'sales', 'sale-items', 'purchases',
        'purchase-items', 'expenses', 'payments', 'cashbook', 'repairs',
        'repair-updates', 'inventory-transactions', 'users', 'roles',
        'permissions', 'settings', 'notifications', 'manual-repair-receipts',
        'mobile-wallet-transactions', 'patients', 'assistants', 'hospital-prescriptions',
        'hospital-orders', 'hospital-tasks', 'lab-reports', 'radiology-reports',
        'hospital-bills', 'hospital-bill-items', 'master-catalogs', 'licenses',
        'audit-logs'
    ] as $resource) {
        Route::apiResource($resource, ResourceController::class)->parameters([$resource => 'id']);
    }
});
