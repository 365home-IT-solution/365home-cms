<?php

use App\Http\Controllers\Api\Admin\Minihouse\AmenityController;
use App\Http\Controllers\Api\Admin\Minihouse\AnnouncementController;
use App\Http\Controllers\Api\Admin\Minihouse\BuildingController;
use App\Http\Controllers\Api\Admin\Minihouse\ChatController as AdminChatController;
use App\Http\Controllers\Api\Admin\Minihouse\ContractController;
use App\Http\Controllers\Api\Admin\Minihouse\ContractDocumentController;
use App\Http\Controllers\Api\Admin\Minihouse\InvoiceController;
use App\Http\Controllers\Api\Admin\Minihouse\InvoicePaymentController;
use App\Http\Controllers\Api\Admin\Minihouse\MeteringReadingController;
use App\Http\Controllers\Api\Admin\Minihouse\ReminderController;
use App\Http\Controllers\Api\Admin\Minihouse\ReportController;
use App\Http\Controllers\Api\Admin\Minihouse\ResidenceDeclarationController;
use App\Http\Controllers\Api\Admin\Minihouse\RoomController;
use App\Http\Controllers\Api\Admin\Minihouse\SurchargeController;
use App\Http\Controllers\Api\Admin\Minihouse\TenantController;
use App\Http\Controllers\Api\Admin\Minihouse\TransactionController;
use App\Http\Controllers\Api\Minihouse\ContractDocumentPreviewController;
use App\Http\Controllers\Api\Minihouse\ContractVerifyController;
use App\Http\Controllers\Api\Minihouse\Portal\ChatController as PortalChatController;
use App\Http\Controllers\Api\Minihouse\Portal\ContractDocumentPortalController;
use App\Http\Controllers\Api\Minihouse\Portal\TenantAuthApiController;
use App\Http\Controllers\Api\Minihouse\Portal\TenantPortalApiController;
use App\Http\Controllers\Api\Minihouse\Public\AmenityController as PublicAmenityController;
use App\Http\Controllers\Api\Minihouse\Public\BuildingController as PublicBuildingController;
use App\Http\Controllers\Api\Minihouse\Public\RentalInquiryController;
use App\Http\Controllers\Api\Minihouse\Public\RoomController as PublicRoomController;
use App\Http\Controllers\Api\Minihouse\Public\ZoneController as PublicZoneController;
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
| (InvoiceController::generate, dùng lại InvoiceGenerationService), kích hoạt thủ công gửi thông báo
| nhắc việc đến hạn (ReminderController::sendDue(), dùng chung logic với cron
| SendReminderNotificationsCommand qua ReminderNotificationService::sendDue()).
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/minihouse')->name('api.admin.minihouse.')->group(function () {
    Route::apiResource('buildings', BuildingController::class)->except(['show'])->parameters(['buildings' => 'id']);
    Route::get('buildings/{id}', [BuildingController::class, 'show'])->name('buildings.show');

    Route::apiResource('rooms', RoomController::class)->except(['show'])->parameters(['rooms' => 'id']);
    Route::get('rooms/{id}', [RoomController::class, 'show'])->name('rooms.show');

    // Module Metering (tách riêng khỏi Minihouse — Modules/Metering) — chỉ quản lý CHỈ SỐ điện/nước
    // theo phòng/tháng, đơn giá vẫn ở Building/Contract (xem InvoiceController). Dùng chung quyền
    // 'rooms' — cùng convention với Filament Resource (MeteringReadingResource::permissionGroup()).
    Route::apiResource('metering-readings', MeteringReadingController::class)->except(['show'])->parameters(['metering-readings' => 'id']);
    Route::get('metering-readings/{id}', [MeteringReadingController::class, 'show'])->name('metering-readings.show');

    Route::get('amenities', [AmenityController::class, 'index'])->name('amenities.index');
    Route::post('amenities', [AmenityController::class, 'store'])->name('amenities.store');
    Route::put('amenities/{id}', [AmenityController::class, 'update'])->name('amenities.update');
    Route::delete('amenities/{id}', [AmenityController::class, 'destroy'])->name('amenities.destroy');

    Route::apiResource('tenants', TenantController::class)->except(['show'])->parameters(['tenants' => 'id']);
    Route::get('tenants/{id}', [TenantController::class, 'show'])->name('tenants.show');
    // POST cùng URL PATCH/PUT ở trên, trỏ ĐÚNG vào TenantController::update() — PHP không tự parse
    // được multipart/form-data (ảnh CCCD) gửi qua PUT/PATCH (giới hạn của PHP, không phải Laravel),
    // Postman/nhiều client chỉ đính kèm file được qua POST. Đặt SAU apiResource nên không đụng route
    // POST /tenants (store, không có {id}) đã đăng ký ở trên.
    Route::post('tenants/{id}', [TenantController::class, 'update'])->name('tenants.update.post');

    Route::apiResource('contracts', ContractController::class)->except(['show'])->parameters(['contracts' => 'id']);
    Route::get('contracts/{id}', [ContractController::class, 'show'])->name('contracts.show');
    Route::post('contracts/{id}/renew', [ContractController::class, 'renew'])->name('contracts.renew');
    Route::post('contracts/{id}/checkout', [ContractController::class, 'checkout'])->name('contracts.checkout');
    Route::post('contracts/{id}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
    Route::post('contracts/{id}/transfer-room', [ContractController::class, 'transferRoom'])->name('contracts.transfer-room');

    // Hợp đồng điện tử (Mức A) — xem docs/be-minihouse-contract-signing.md mục 4 và
    // ContractDocumentController.
    Route::get('contracts/{id}/document', [ContractDocumentController::class, 'show'])->name('contracts.document.show');
    Route::patch('contracts/{id}/document', [ContractDocumentController::class, 'update'])->name('contracts.document.update');
    Route::post('contracts/{id}/document/send', [ContractDocumentController::class, 'send'])->name('contracts.document.send');
    Route::post('contracts/{id}/document/sign', [ContractDocumentController::class, 'sign'])->name('contracts.document.sign');
    Route::post('contracts/{id}/document/recall', [ContractDocumentController::class, 'recall'])->name('contracts.document.recall');
    Route::get('contracts/{id}/document/audit', [ContractDocumentController::class, 'audit'])->name('contracts.document.audit');

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

    // send-due: kích hoạt thủ công NGAY logic gửi thông báo nhắc việc đến hạn đang chạy cron hàng
    // ngày (xem ReminderController::sendDue()) — đặt TRƯỚC apiResource để rõ ràng đây là 1 action
    // riêng, không phải resource {id}.
    Route::post('reminders/send-due', [ReminderController::class, 'sendDue'])->name('reminders.send-due');
    Route::apiResource('reminders', ReminderController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['reminders' => 'id']);

    Route::apiResource('residence-declarations', ResidenceDeclarationController::class)->except(['show'])->parameters(['residence-declarations' => 'id']);
    Route::get('residence-declarations/{id}', [ResidenceDeclarationController::class, 'show'])->name('residence-declarations.show');
    Route::post('residence-declarations/{id}/mark-declared', [ResidenceDeclarationController::class, 'markDeclared'])->name('residence-declarations.mark-declared');

    Route::apiResource('announcements', AnnouncementController::class)->only(['index', 'store', 'destroy'])->parameters(['announcements' => 'id']);

    // Báo cáo — mirror tinh thần Modules\Dashboard\Http\Controllers\ReportController bên Home (Home
    // dùng đủ 7 báo cáo theo nghiệp vụ đặt phòng ngắn hạn: receptionist/end-of-day/booking/revenue/
    // room/customer/financial); MiniHouse thay bằng đúng 4 báo cáo khớp nghiệp vụ cho thuê dài hạn
    // (financial/debts/occupancy/contracts) — không có khái niệm "lễ tân/đặt phòng theo ngày".
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('overview', [ReportController::class, 'overview'])->name('overview');
        Route::get('financial', [ReportController::class, 'financial'])->name('financial');
        Route::get('debts', [ReportController::class, 'debts'])->name('debts');
        Route::get('occupancy', [ReportController::class, 'occupancy'])->name('occupancy');
        Route::get('contracts', [ReportController::class, 'contracts'])->name('contracts');
        Route::get('rankings', [ReportController::class, 'rankings'])->name('rankings');
    });

    // Chat khách thuê <-> nhân viên (phía nhân viên) — mirror api.admin.chat.* của Home, xem
    // App\Http\Controllers\Api\Admin\Minihouse\ChatController.
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/', [AdminChatController::class, 'index'])->name('index');
        Route::get('{id}', [AdminChatController::class, 'show'])->name('show');
        Route::get('{id}/messages', [AdminChatController::class, 'messages'])->name('messages');
        Route::post('{id}/messages', [AdminChatController::class, 'send'])->name('send');
        Route::post('{id}/read', [AdminChatController::class, 'read'])->name('read');
    });
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

// Tra cứu công khai hợp đồng điện tử đã ký (mục 9) — không cần đăng nhập, chỉ cần đúng mã.
Route::get('minihouse/contract-verify/{code}', [ContractVerifyController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('api.minihouse.contract-verify');

// Xem trước HTML hợp đồng điện tử qua link CÓ CHỮ KÝ (Laravel signed route, tự hết hạn — xem
// ContractDocumentPreviewController) — dùng cho html_url trả về ở GET .../document, KHÔNG qua
// auth:sanctum vì app mở link này trong WebView, không tự gắn được header Authorization.
Route::get('minihouse/contract-document/{document}/html', [ContractDocumentPreviewController::class, 'html'])
    ->middleware('signed')
    ->name('api.minihouse.contract-document.html');

/*
|--------------------------------------------------------------------------
| MiniHouse — API trang tìm phòng CÔNG KHAI (chưa đăng nhập, chưa là khách thuê)
|--------------------------------------------------------------------------
| Dành cho người XEM/TÌM phòng còn trống (như trang chủ Home cho khách đặt phòng ngắn hạn) — KHÔNG
| cần Bearer token nào. Chỉ liệt kê Toà nhà đang bật + Phòng đang "Trống" (Room::scopeAvailable()),
| không lộ trường nội bộ (chủ nhà, tài khoản ngân hàng, khoá cổng thanh toán...). Kết thúc bằng 1
| "lead" (RentalInquiryController::store) để nhân viên tự gọi lại tư vấn — MiniHouse không có khái
| niệm đặt phòng/thanh toán online ngay như Home, hợp đồng luôn do nhân viên tạo tay sau khi chốt.
|--------------------------------------------------------------------------
*/
Route::prefix('minihouse/public')->name('api.minihouse.public.')->middleware('throttle:60,1')->group(function () {
    Route::get('zones', [PublicZoneController::class, 'index'])->name('zones.index');
    Route::get('amenities', [PublicAmenityController::class, 'index'])->name('amenities.index');

    Route::get('buildings', [PublicBuildingController::class, 'index'])->name('buildings.index');
    Route::get('buildings/{id}', [PublicBuildingController::class, 'show'])->whereNumber('id')->name('buildings.show');

    Route::get('rooms', [PublicRoomController::class, 'index'])->name('rooms.index');
    Route::get('rooms/{id}', [PublicRoomController::class, 'show'])->name('rooms.show');

    // Throttle CHẶT hơn hẳn (5 lần/phút/IP, cùng mức với TenantFeedbackController::store) — endpoint
    // GHI DỮ LIỆU công khai không đăng nhập, không giới hạn thì 1 script có thể spam hàng loạt lead
    // giả cho nhân viên gọi nhầm.
    Route::post('rental-inquiries', [RentalInquiryController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('rental-inquiries.store');
});

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
        Route::get('notifications/unread-count', [TenantPortalApiController::class, 'unreadNotificationCount'])->name('notifications.unread-count');
        Route::get('invoices', [TenantPortalApiController::class, 'invoices'])->name('invoices.index');
        Route::get('invoices/{invoice}', [TenantPortalApiController::class, 'showInvoice'])->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [TenantPortalApiController::class, 'invoicePdf'])->name('invoices.pdf');
        Route::post('invoices/{invoice}/pay', [TenantPortalApiController::class, 'payInvoice'])->name('invoices.pay');
        Route::get('payments', [TenantPortalApiController::class, 'payments'])->name('payments.index');
        Route::get('contracts', [TenantPortalApiController::class, 'contracts'])->name('contracts.index');
        Route::get('contracts/{contract}', [TenantPortalApiController::class, 'showContract'])->name('contracts.show');
        Route::get('contracts/{contract}/pdf', [TenantPortalApiController::class, 'contractPdf'])->name('contracts.pdf');
        Route::post('contracts/{contract}/renewal-request', [TenantPortalApiController::class, 'requestRenewal'])->name('contracts.renewal-request');
        Route::post('contracts/{contract}/checkout-request', [TenantPortalApiController::class, 'requestCheckout'])->name('contracts.checkout-request');
        Route::get('contracts/{contract}/document', [ContractDocumentPortalController::class, 'show'])->name('contracts.document.show');
        Route::post('contracts/{contract}/document/otp', [ContractDocumentPortalController::class, 'otp'])->name('contracts.document.otp');
        Route::post('contracts/{contract}/document/sign', [ContractDocumentPortalController::class, 'sign'])->name('contracts.document.sign');
        Route::get('feedback', [TenantPortalApiController::class, 'feedback'])->name('feedback.index');
        Route::post('feedback', [TenantPortalApiController::class, 'storeFeedback'])->name('feedback.store');
        Route::get('profile', [TenantPortalApiController::class, 'profile'])->name('profile.show');
        Route::put('profile', [TenantPortalApiController::class, 'updateProfile'])->name('profile.update');
        Route::post('password', [TenantPortalApiController::class, 'updatePassword'])->name('password.update');
        Route::post('push-token', [TenantPortalApiController::class, 'registerPushToken'])->name('push-token.register');
        Route::delete('push-token', [TenantPortalApiController::class, 'unregisterPushToken'])->name('push-token.unregister');

        // Chat khách thuê <-> nhân viên (phía khách thuê) — mirror api.chat.* của Home, xem
        // App\Http\Controllers\Api\Minihouse\Portal\ChatController.
        Route::get('chat', [PortalChatController::class, 'show'])->name('chat.show');
        Route::get('chat/messages', [PortalChatController::class, 'messages'])->name('chat.messages');
        Route::post('chat/messages', [PortalChatController::class, 'send'])->name('chat.send');
        Route::post('chat/read', [PortalChatController::class, 'read'])->name('chat.read');
    });
});
