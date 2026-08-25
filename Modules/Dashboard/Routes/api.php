<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;
use Modules\Dashboard\Http\Controllers\ReportController;

Route::middleware(['auth:sanctum', 'admin.api'])
    ->prefix('admin/dashboard')
    ->group(function () {
        Route::get('/overview', [DashboardController::class, 'overview']);
        Route::get('/kpi-stats', [DashboardController::class, 'kpiStats']);
        Route::get('/rankings', [DashboardController::class, 'rankings']);
        Route::get('/occupancy', [DashboardController::class, 'occupancy']);
        Route::get('/occupancy-top', [DashboardController::class, 'occupancyTop']);
        Route::get('/front-desk', [DashboardController::class, 'frontDesk']);
    });

Route::middleware(['auth:sanctum', 'admin.api'])
    ->prefix('admin/reports')
    ->name('api.admin.reports.')
    ->group(function () {
        Route::get('/receptionist', [ReportController::class, 'receptionist'])->name('receptionist');
        Route::get('/end-of-day', [ReportController::class, 'endOfDay'])->name('end-of-day');
        Route::get('/booking', [ReportController::class, 'booking'])->name('booking');
        Route::get('/revenue', [ReportController::class, 'revenue'])->name('revenue');
        Route::get('/room', [ReportController::class, 'room'])->name('room');
        Route::get('/customer', [ReportController::class, 'customer'])->name('customer');
        Route::get('/financial', [ReportController::class, 'financial'])->name('financial');
    });
