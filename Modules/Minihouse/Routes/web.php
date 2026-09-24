<?php

use Illuminate\Support\Facades\Route;
use Modules\Minihouse\Http\Controllers\ContractPrintController;
use Modules\Minihouse\Http\Controllers\InvoicePrintController;
use Modules\Minihouse\Http\Controllers\PanoramaTourController;
use Modules\Minihouse\Http\Controllers\Portal\ChatPortalController;
use Modules\Minihouse\Http\Controllers\Portal\TenantAuthController;
use Modules\Minihouse\Http\Controllers\Portal\TenantPortalController;
use Modules\Minihouse\Http\Controllers\TenantFeedbackController;

// Route thường (ngoài Filament) — panel /minihouse/admin tự đăng ký route qua
// App\Providers\Filament\MinihouseAdminPanelProvider, không cần khai báo thêm ở đây. Đặt 3 route in
// dưới đây CÙNG TIỀN TỐ /minihouse/admin (dù không phải route Filament) chỉ để URL nhất quán với
// panel — mọi nơi gọi tới đều qua route(), không có chỗ nào build URL này bằng chuỗi tay.

Route::middleware('auth')
    ->get('/minihouse/admin/contracts/{contract}/print', [ContractPrintController::class, 'show'])
    ->name('minihouse.contracts.print');

Route::middleware('auth')
    ->get('/minihouse/admin/invoices/{invoice}/print', [InvoicePrintController::class, 'show'])
    ->name('minihouse.invoices.print');

// Đặt TRƯỚC {invoice} ở trên trong route file không quan trọng vì path khác nhau ("print-bulk" so
// với "{invoice}/print") — không đụng route-model-binding của route show().
Route::middleware('auth')
    ->get('/minihouse/admin/invoices/print-bulk', [InvoicePrintController::class, 'bulk'])
    ->name('minihouse.invoices.print-bulk');

// Kênh phản hồi/đánh giá khách thuê — CÔNG KHAI, không qua middleware 'auth' (khách quét QR/mở link
// gắn theo phòng, không có tài khoản đăng nhập nào cả). Xem TenantFeedbackController.
Route::get('/minihouse/feedback', [TenantFeedbackController::class, 'create'])
    ->name('minihouse.feedback.create');

// throttle — endpoint công khai, không đăng nhập, không captcha: không giới hạn tần suất thì 1 script
// có thể spam hàng loạt đánh giá giả mạo tên/SĐT khách thuê thật cho bất kỳ phòng nào. 5 lần/phút/IP
// vẫn đủ thoải mái cho người dùng thật (không ai gửi feedback nhiều lần liên tục thật sự).
Route::post('/minihouse/feedback', [TenantFeedbackController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('minihouse.feedback.store');

// Tour ảo 360° CÔNG KHAI — khách xem sơ đồ phòng qua ảnh toàn cảnh (Pannellum), KHÔNG cần đăng nhập,
// cùng nguyên tắc route công khai với TenantFeedbackController (dán QR trong toà nhà/phòng, hoặc gửi
// link trực tiếp cho khách). Xem PanoramaTourController.
Route::get('/minihouse/tour/{building}', [PanoramaTourController::class, 'show'])
    ->whereNumber('building')
    ->name('minihouse.tour.show');

Route::get('/minihouse/tour/{building}/data.json', [PanoramaTourController::class, 'data'])
    ->whereNumber('building')
    ->name('minihouse.tour.data');

// Sơ đồ tầng isometric TỰ SINH từ dữ liệu 360° (không cần nhập toạ độ tay) — xem
// PanoramaFloorPlanLayoutService. Đặt TRƯỚC "{scene}" bên dưới vì "floorplan" không phải số nên
// ->whereNumber('scene') của route đó sẽ không khớp nhầm, nhưng để tường minh đặt trước cho dễ đọc.
Route::get('/minihouse/tour/{building}/floorplan', [PanoramaTourController::class, 'floorplan'])
    ->whereNumber('building')
    ->name('minihouse.tour.floorplan');

// Đặt SAU "/data.json" — path khác nhau nên không đụng route-model-binding, nhưng để cạnh nhau cho
// dễ đọc (route "vào thẳng 1 điểm" và "dữ liệu tour" cùng thuộc {building}).
Route::get('/minihouse/tour/{building}/{scene}', [PanoramaTourController::class, 'scene'])
    ->whereNumber(['building', 'scene'])
    ->name('minihouse.tour.scene');

// Portal khách thuê — đăng nhập bằng OTP theo SĐT (guard "tenant", KHÔNG dùng chung guard "web" của
// nhân viên/chủ nhà), xem TenantAuthController/TenantPortalController. Đặt CHUNG group prefix
// "minihouse/portal" cho gọn, không cần route-model-binding nào ở đây (mọi id đều tự kiểm tra quyền
// sở hữu thủ công bên trong controller, xem TenantPortalController::invoiceBelongsToTenant()).
Route::prefix('minihouse/portal')->name('minihouse.portal.')->group(function () {
    Route::get('/login', [TenantAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [TenantAuthController::class, 'requestOtp'])->name('login.request-otp');
    Route::post('/login/password', [TenantAuthController::class, 'loginWithPassword'])->name('login.password');
    Route::get('/login/verify', [TenantAuthController::class, 'showVerify'])->name('login.verify');
    Route::post('/login/verify', [TenantAuthController::class, 'verifyOtp'])->name('login.verify.submit');
    Route::get('/login/select-profile', [TenantAuthController::class, 'showSelectProfile'])->name('login.select-profile');
    Route::post('/login/select-profile', [TenantAuthController::class, 'selectProfile'])->name('login.select-profile.submit');
    Route::post('/logout', [TenantAuthController::class, 'logout'])->name('logout');

    Route::middleware('auth:tenant')->group(function () {
        Route::get('/', [TenantPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/invoices', [TenantPortalController::class, 'invoices'])->name('invoices.index');
        Route::get('/invoices/{invoice}', [TenantPortalController::class, 'showInvoice'])->name('invoices.show');
        Route::post('/invoices/{invoice}/pay', [TenantPortalController::class, 'payInvoice'])->name('invoices.pay');
        Route::get('/password', [TenantPortalController::class, 'showPasswordForm'])->name('password');
        Route::post('/password', [TenantPortalController::class, 'updatePassword'])->name('password.update');
        Route::get('/notifications', [TenantPortalController::class, 'notifications'])->name('notifications');
        Route::get('/feedback', [TenantPortalController::class, 'showFeedbackForm'])->name('feedback');
        Route::post('/feedback', [TenantPortalController::class, 'storeFeedback'])->name('feedback.store');
        Route::get('/payments', [TenantPortalController::class, 'payments'])->name('payments.index');
        Route::get('/contracts', [TenantPortalController::class, 'contracts'])->name('contracts.index');
        Route::get('/contracts/{contract}', [TenantPortalController::class, 'showContract'])->name('contracts.show');

        // Chat với nhân viên toà nhà — xem ChatPortalController.
        Route::get('/chat', [ChatPortalController::class, 'show'])->name('chat.show');
        Route::get('/chat/messages', [ChatPortalController::class, 'messages'])->name('chat.messages');
        Route::post('/chat/messages', [ChatPortalController::class, 'send'])->name('chat.send');
        Route::post('/chat/read', [ChatPortalController::class, 'read'])->name('chat.read');
    });
});
