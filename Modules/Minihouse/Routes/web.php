<?php

use Illuminate\Support\Facades\Route;
use Modules\Minihouse\Http\Controllers\ContractPrintController;

// Route thường (ngoài Filament) — panel /minihouse-admin tự đăng ký route qua
// App\Providers\Filament\MinihouseAdminPanelProvider, không cần khai báo thêm ở đây.

Route::middleware('auth')
    ->get('/minihouse-admin/contracts/{contract}/print', [ContractPrintController::class, 'show'])
    ->name('minihouse.contracts.print');
