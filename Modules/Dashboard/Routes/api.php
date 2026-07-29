<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum', 'admin.api'])
    ->prefix('admin/dashboard')
    ->group(function () {
        Route::get('/overview', [DashboardController::class, 'overview']);
        Route::get('/kpi-stats', [DashboardController::class, 'kpiStats']);
        Route::get('/rankings', [DashboardController::class, 'rankings']);
        Route::get('/occupancy', [DashboardController::class, 'occupancy']);
    });
