<?php

use App\Http\Controllers\Api\Admin\Minihouse\AmenityController;
use App\Http\Controllers\Api\Admin\Minihouse\BuildingController;
use App\Http\Controllers\Api\Admin\Minihouse\ContractController;
use App\Http\Controllers\Api\Admin\Minihouse\InvoiceController;
use App\Http\Controllers\Api\Admin\Minihouse\InvoicePaymentController;
use App\Http\Controllers\Api\Admin\Minihouse\ReminderController;
use App\Http\Controllers\Api\Admin\Minihouse\ResidenceDeclarationController;
use App\Http\Controllers\Api\Admin\Minihouse\RoomController;
use App\Http\Controllers\Api\Admin\Minihouse\SurchargeController;
use App\Http\Controllers\Api\Admin\Minihouse\TenantController;
use App\Http\Controllers\Api\Admin\Minihouse\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MiniHouse — API cho quản lý cho thuê theo tháng (nhà trọ/chung cư mini)
|--------------------------------------------------------------------------
| Dùng CHUNG đăng nhập admin có sẵn: POST /api/admin/login (App\Models\User, xem
| App\Http\Controllers\Api\Admin\AuthController) rồi gọi mọi endpoint dưới đây với
| Authorization: Bearer <token> — tài khoản phải có quyền panel MiniHouse (permission
| access_minihouse, xem MinihousePermissionSeeder) thì mới qua được các permission check riêng
| từng resource bên dưới; auth:sanctum + admin.api chỉ đảm bảo đó LÀ 1 App\Models\User nội bộ.
|
| QUAN TRỌNG — lọc theo toà nhà: các Resource MiniHouse tự lọc theo toà nhà được quản lý (Room,
| Contract, Invoice...) qua global scope ActiveBuildingScope — nhưng scope đó CHỈ bật trong đúng
| panel Filament minihouse-admin (xem ActiveBuildingScope::isPanelActive()). Gọi qua API thì scope
| đó luôn tắt, nên MỌI controller dưới đây tự lọc lại thủ công bằng
| App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding (dựa trên
| User::rootBuildingIds()) — không tin vào global scope của model.
|
| Phạm vi lần này: CRUD cơ bản cho mọi model MiniHouse hiện có. CHƯA đưa vào API các luồng nghiệp
| vụ nâng cao chỉ có ở panel Filament: Gia hạn/Thanh lý/Chuyển phòng hợp đồng (xem
| EditContract::getHeaderActions()), Lập hoá đơn hàng loạt theo tháng (InvoiceGenerationService),
| gửi thông báo nhắc việc tự động. Cần thì bổ sung action riêng sau.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/minihouse')->name('api.admin.minihouse.')->group(function () {
    Route::apiResource('buildings', BuildingController::class)->except(['show'])->parameters(['buildings' => 'id']);
    Route::get('buildings/{id}', [BuildingController::class, 'show'])->name('buildings.show');

    Route::apiResource('rooms', RoomController::class)->except(['show'])->parameters(['rooms' => 'id']);
    Route::get('rooms/{id}', [RoomController::class, 'show'])->name('rooms.show');

    Route::get('amenities', [AmenityController::class, 'index'])->name('amenities.index');
    Route::post('amenities', [AmenityController::class, 'store'])->name('amenities.store');
    Route::put('amenities/{id}', [AmenityController::class, 'update'])->name('amenities.update');
    Route::delete('amenities/{id}', [AmenityController::class, 'destroy'])->name('amenities.destroy');

    Route::apiResource('tenants', TenantController::class)->except(['show'])->parameters(['tenants' => 'id']);
    Route::get('tenants/{id}', [TenantController::class, 'show'])->name('tenants.show');

    Route::apiResource('contracts', ContractController::class)->except(['show'])->parameters(['contracts' => 'id']);
    Route::get('contracts/{id}', [ContractController::class, 'show'])->name('contracts.show');

    Route::apiResource('invoices', InvoiceController::class)->except(['show'])->parameters(['invoices' => 'id']);
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoiceId}/payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
    Route::post('invoices/{invoiceId}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
    Route::delete('invoices/{invoiceId}/payments/{paymentId}', [InvoicePaymentController::class, 'destroy'])->name('invoices.payments.destroy');

    Route::apiResource('transactions', TransactionController::class)->except(['show'])->parameters(['transactions' => 'id']);
    Route::get('transactions/{id}', [TransactionController::class, 'show'])->name('transactions.show');

    Route::apiResource('surcharges', SurchargeController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['surcharges' => 'id']);

    Route::apiResource('reminders', ReminderController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['reminders' => 'id']);

    Route::apiResource('residence-declarations', ResidenceDeclarationController::class)->except(['show'])->parameters(['residence-declarations' => 'id']);
    Route::get('residence-declarations/{id}', [ResidenceDeclarationController::class, 'show'])->name('residence-declarations.show');
    Route::post('residence-declarations/{id}/mark-declared', [ResidenceDeclarationController::class, 'markDeclared'])->name('residence-declarations.mark-declared');
});
