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
use App\Http\Controllers\Api\Minihouse\Portal\TenantAuthApiController;
use App\Http\Controllers\Api\Minihouse\Portal\TenantPortalApiController;
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
| Bao gồm CRUD cơ bản cho mọi model MiniHouse hiện có + các luồng nghiệp vụ nâng cao mirror đúng
| panel Filament: Gia hạn/Thanh lý/Chuyển phòng hợp đồng (xem ContractController::renew/checkout/
| transferRoom, đúng logic EditContract::getHeaderActions()), Lập hoá đơn hàng loạt theo tháng
| (InvoiceController::generate, dùng lại InvoiceGenerationService). CHƯA có: gửi thông báo nhắc
| việc tự động qua API (hiện chỉ chạy theo lịch cron, xem SendReminderNotificationsCommand).
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
    Route::post('contracts/{id}/renew', [ContractController::class, 'renew'])->name('contracts.renew');
    Route::post('contracts/{id}/checkout', [ContractController::class, 'checkout'])->name('contracts.checkout');
    Route::post('contracts/{id}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
    Route::post('contracts/{id}/transfer-room', [ContractController::class, 'transferRoom'])->name('contracts.transfer-room');

    Route::post('invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
    Route::apiResource('invoices', InvoiceController::class)->except(['show'])->parameters(['invoices' => 'id']);
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('invoices/{id}/qr', [InvoiceController::class, 'generateQr'])->name('invoices.qr');
    Route::post('invoices/{id}/momo', [InvoiceController::class, 'generateMomo'])->name('invoices.momo');
    Route::post('invoices/{id}/vnpay', [InvoiceController::class, 'generateVnpay'])->name('invoices.vnpay');
    Route::get('invoices/{invoiceId}/payments', [InvoicePaymentController::class, 'index'])->name('invoices.payments.index');
    Route::post('invoices/{invoiceId}/payments', [InvoicePaymentController::class, 'store'])->name('invoices.payments.store');
    Route::post('invoices/{invoiceId}/payments/{paymentId}/approve', [InvoicePaymentController::class, 'approve'])->name('invoices.payments.approve');
    Route::delete('invoices/{invoiceId}/payments/{paymentId}', [InvoicePaymentController::class, 'destroy'])->name('invoices.payments.destroy');

    Route::apiResource('transactions', TransactionController::class)->except(['show'])->parameters(['transactions' => 'id']);
    Route::get('transactions/{id}', [TransactionController::class, 'show'])->name('transactions.show');

    Route::apiResource('surcharges', SurchargeController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['surcharges' => 'id']);

    Route::apiResource('reminders', ReminderController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['reminders' => 'id']);

    Route::apiResource('residence-declarations', ResidenceDeclarationController::class)->except(['show'])->parameters(['residence-declarations' => 'id']);
    Route::get('residence-declarations/{id}', [ResidenceDeclarationController::class, 'show'])->name('residence-declarations.show');
    Route::post('residence-declarations/{id}/mark-declared', [ResidenceDeclarationController::class, 'markDeclared'])->name('residence-declarations.mark-declared');
});

// Webhook PayOS — công khai, KHÔNG qua auth:sanctum/admin.api vì PayOS gọi thẳng vào đây, không có
// bearer token nội bộ. Xác thực bằng chữ ký HMAC riêng của PayOS (verifyPaymentWebhookData), xem
// App\Http\Controllers\Api\Minihouse\PayOsWebhookController.
Route::post('minihouse/webhook/payos', [\App\Http\Controllers\Api\Minihouse\PayOsWebhookController::class, 'handle'])
    ->name('api.minihouse.webhook.payos');

// Webhook (IPN) MoMo — công khai, tự xác thực chữ ký HMAC-SHA256 riêng (không có SDK PHP chính
// thức để dùng lại như PayOS), xem App\Http\Controllers\Api\Minihouse\MomoWebhookController.
Route::post('minihouse/webhook/momo', [\App\Http\Controllers\Api\Minihouse\MomoWebhookController::class, 'handle'])
    ->name('api.minihouse.webhook.momo');

// IPN VNPay — công khai, GET (VNPay gọi IPN bằng query string, không phải POST body) — xem
// App\Http\Controllers\Api\Minihouse\VnpayIpnController.
Route::get('minihouse/webhook/vnpay', [\App\Http\Controllers\Api\Minihouse\VnpayIpnController::class, 'handle'])
    ->name('api.minihouse.webhook.vnpay');

/*
|--------------------------------------------------------------------------
| MiniHouse — API Portal khách thuê (app di động/bên thứ 3)
|--------------------------------------------------------------------------
| Bản API của Portal web (session, Modules\Minihouse\Http\Controllers\Portal\*, prefix
| "minihouse/portal") — CÙNG NGHIỆP VỤ, dùng CHUNG TenantPortalService +
| InteractsWithTenantPortalData nên không lệch nhau. Đăng nhập bằng OTP theo SĐT (Zalo trước, SMS dự
| phòng) HOẶC SĐT + mật khẩu tự đặt (xem App\Http\Controllers\Api\Minihouse\Portal\
| TenantAuthApiController) — trả về Bearer token Sanctum RIÊNG của Tenant (Modules\Minihouse\App\
| Models\Tenant dùng HasApiTokens, đa hình CHUNG bảng personal_access_tokens với App\Models\User
| nhưng hoàn toàn TÁCH BIỆT theo tokenable_type). Nhóm route bên dưới cần auth:sanctum + tenant.api
| (TenantApiAuth — đảm bảo token thuộc ĐÚNG 1 Tenant, không lỡ dùng nhầm token admin).
*/
Route::prefix('minihouse/portal')->name('api.minihouse.portal.')->group(function () {
    Route::post('otp/request', [TenantAuthApiController::class, 'requestOtp'])->name('otp.request');
    Route::post('otp/verify', [TenantAuthApiController::class, 'verifyOtp'])->name('otp.verify');
    Route::post('select-profile', [TenantAuthApiController::class, 'selectProfile'])->name('select-profile');
    Route::post('login/password', [TenantAuthApiController::class, 'loginWithPassword'])->name('login.password');

    Route::middleware(['auth:sanctum', 'tenant.api'])->group(function () {
        Route::post('logout', [TenantAuthApiController::class, 'logout'])->name('logout');

        Route::get('dashboard', [TenantPortalApiController::class, 'dashboard'])->name('dashboard');
        Route::get('notifications', [TenantPortalApiController::class, 'notifications'])->name('notifications');
        Route::get('invoices', [TenantPortalApiController::class, 'invoices'])->name('invoices.index');
        Route::get('invoices/{invoice}', [TenantPortalApiController::class, 'showInvoice'])->name('invoices.show');
        Route::post('invoices/{invoice}/pay', [TenantPortalApiController::class, 'payInvoice'])->name('invoices.pay');
        Route::get('payments', [TenantPortalApiController::class, 'payments'])->name('payments.index');
        Route::get('contracts', [TenantPortalApiController::class, 'contracts'])->name('contracts.index');
        Route::get('contracts/{contract}', [TenantPortalApiController::class, 'showContract'])->name('contracts.show');
        Route::post('feedback', [TenantPortalApiController::class, 'storeFeedback'])->name('feedback.store');
        Route::post('password', [TenantPortalApiController::class, 'updatePassword'])->name('password.update');
    });
});
