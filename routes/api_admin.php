<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CccdController as AdminCccdController;
use App\Http\Controllers\Api\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Api\Admin\AllowanceTypeController;
use App\Http\Controllers\Api\Admin\CustomerCompanionController as AdminCustomerCompanionController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DailyRoomHoldController as AdminDailyRoomHoldController;
use App\Http\Controllers\Api\Admin\DeductionTypeController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\OrderPaymentController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Api\Admin\RoomBlockController as AdminRoomBlockController;
use App\Http\Controllers\Api\Admin\RoomController as AdminRoomController;
use App\Http\Controllers\Api\Admin\RoomPricingController as AdminRoomPricingController;
use App\Http\Controllers\Api\Admin\RoomPromotionController as AdminRoomPromotionController;
use App\Http\Controllers\Api\Admin\RoomTimeSlotController as AdminRoomTimeSlotController;
use App\Http\Controllers\Api\Admin\RoomTypeController as AdminRoomTypeController;
use App\Http\Controllers\Api\Admin\SalaryTemplateController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
use App\Http\Controllers\Api\Admin\ServiceController as AdminServiceController;
use App\Http\Controllers\Api\Admin\TagController as AdminTagController;
use App\Http\Controllers\Api\Admin\TimeSlotController as AdminTimeSlotController;
use App\Http\Controllers\Api\Admin\TimeSlotHoldController as AdminTimeSlotHoldController;
use App\Http\Controllers\Api\Admin\UnlockController as AdminUnlockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Auth — Đăng nhập cho tài khoản quản trị/nhân viên nội bộ (App\Models\User)
| POST /api/admin/login   → Đăng nhập email + password, trả Sanctum token
| POST /api/admin/logout  → Thu hồi token hiện tại
| GET  /api/admin/me      → Thông tin admin đang đăng nhập
|
| Token này (khác với token khách hàng ở /api/auth/*) là điều kiện bắt buộc
| để gọi mọi route có middleware `admin.api` bên dưới (Chat Admin, Departments,
| Positions...). Không dùng chung token khách hàng cho các route admin.
|--------------------------------------------------------------------------
*/
Route::post('admin/login', [AdminAuthController::class, 'login'])->name('api.admin.login')->middleware('throttle:6,1');

Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');
    Route::get('me',      [AdminAuthController::class, 'me'])->name('me');
});

Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/chat')->name('api.admin.chat.')->group(function () {
    Route::get('/',                       [AdminChatController::class, 'index'])->name('index');
    Route::get('/{id}',                   [AdminChatController::class, 'show'])->name('show');
    Route::post('/{id}/messages',         [AdminChatController::class, 'send'])->name('send');
    Route::post('/{id}/read',             [AdminChatController::class, 'read'])->name('read');
});

/*
|--------------------------------------------------------------------------
| Employee — Dữ liệu nền tảng cho module Nhân viên (Phòng ban / Chức danh)
| GET    /api/admin/departments      → Danh sách phòng ban (?all=1 để lấy cả inactive)
| POST   /api/admin/departments      → Tạo phòng ban mới (dùng cho nút "+" trên form)
| PUT    /api/admin/departments/{id} → Cập nhật phòng ban
| DELETE /api/admin/departments/{id} → Xoá phòng ban
| (Positions tương tự, đổi "departments" → "positions")
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::prefix('departments')->name('departments.')->group(function () {
        Route::get('/',      [DepartmentController::class, 'index'])->name('index');
        Route::post('/',     [DepartmentController::class, 'store'])->name('store');
        Route::put('/{id}',  [DepartmentController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [DepartmentController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    Route::prefix('positions')->name('positions.')->group(function () {
        Route::get('/',      [PositionController::class, 'index'])->name('index');
        Route::post('/',     [PositionController::class, 'store'])->name('store');
        Route::put('/{id}',  [PositionController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [PositionController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });
});

/*
|--------------------------------------------------------------------------
| Employee — Cấu hình lương (Loại lương / Phụ cấp / Giảm trừ / Mẫu lương)
| Dùng cho tab "Thiết lập lương" trên form Nhân viên. 4 API danh mục
| (salary-types, allowance-types, deduction-types) có CRUD giống departments/positions.
| salary-templates gộp sẵn 1 loại lương + lương cơ bản + phụ cấp/giảm trừ áp dụng.
|
| Nhân viên (bảng employees) tham chiếu trực tiếp tới salary_type_id/salary_template_id
| + có 2 bảng pivot employee_allowances/employee_deductions riêng (ghi đè theo từng nhân viên) —
| xem nhóm route "Employee" ngay bên dưới.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::prefix('salary-types')->name('salary-types.')->group(function () {
        Route::get('/',        [SalaryTypeController::class, 'index'])->name('index');
        Route::post('/',       [SalaryTypeController::class, 'store'])->name('store');
        Route::put('/{id}',    [SalaryTypeController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [SalaryTypeController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    Route::prefix('allowance-types')->name('allowance-types.')->group(function () {
        Route::get('/',        [AllowanceTypeController::class, 'index'])->name('index');
        Route::post('/',       [AllowanceTypeController::class, 'store'])->name('store');
        Route::put('/{id}',    [AllowanceTypeController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [AllowanceTypeController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    Route::prefix('deduction-types')->name('deduction-types.')->group(function () {
        Route::get('/',        [DeductionTypeController::class, 'index'])->name('index');
        Route::post('/',       [DeductionTypeController::class, 'store'])->name('store');
        Route::put('/{id}',    [DeductionTypeController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [DeductionTypeController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });

    Route::prefix('salary-templates')->name('salary-templates.')->group(function () {
        Route::get('/',        [SalaryTemplateController::class, 'index'])->name('index');
        Route::get('/{id}',    [SalaryTemplateController::class, 'show'])->name('show')->whereNumber('id');
        Route::post('/',       [SalaryTemplateController::class, 'store'])->name('store');
        Route::put('/{id}',    [SalaryTemplateController::class, 'update'])->name('update')->whereNumber('id');
        Route::delete('/{id}', [SalaryTemplateController::class, 'destroy'])->name('destroy')->whereNumber('id');
    });
});

/*
|--------------------------------------------------------------------------
| Branches / Rooms — dữ liệu nền để admin chọn khi tạo/sửa đơn (room_id)
| GET /api/admin/branches → chi nhánh CHA (parent_id=null, category_type=product) theo đối tác;
|                            super_admin thấy tất cả. Dùng khi user có nhiều chi nhánh cần chọn lọc.
| GET /api/admin/rooms    → id/name/slug các phòng đang hoạt động (is_activated=true) theo đối tác;
|                            ?categories={slug chi nhánh} lọc thêm đúng 1 chi nhánh (gồm danh mục con).
| GET /api/admin/rooms/{id}/time-slots
|                          → khung giờ x ngày của 1 phòng, mặc định 10 ngày — dùng ?offset_days= để
|                            xem thêm 10 ngày tiếp theo, hoặc ?offset_days=-10 để xem 10 ngày TRƯỚC
|                            hôm nay (xem docblock RoomController::timeSlots()).
| POST/DELETE /api/admin/rooms/{id}/time-slot-hold
|                          → giữ/bỏ giữ tạm 1 ô khung giờ x ngày khi admin đang chọn (chưa bấm tạo
|                            đơn) — dùng CHUNG kho dữ liệu + kênh realtime với khách hàng
|                            (TimeSlotHoldController) nhưng định danh bằng chính token admin
|                            ("admin:{user_id}"), không nhận session_id từ client.
| GET /api/admin/rooms/{id}/dates
|                          → lịch phòng theo NGÀY (styles=2) — ?month=YYYY-MM (calendar) hoặc
|                            ?from=&to= (xem trước 1 khoảng, có subtotal/deposit/holding).
| POST/DELETE /api/admin/rooms/{id}/hold
|                          → giữ/bỏ giữ tạm 1 khoảng ngày cho phòng theo NGÀY — cùng nguyên tắc với
|                            time-slot-hold ở trên (định danh admin:{user_id}, dùng chung kho dữ
|                            liệu + kênh realtime với DailyRoomHoldController khách hàng).
| PATCH /api/admin/rooms/{id}/status
|                          → bật/tắt trạng thái hoạt động của phòng (body: status — boolean), map
|                            xuống cột `is_activated` trong bảng products (xem docblock
|                            App\Http\Controllers\Api\Admin\ProductController::updateStatus()).
| GET /api/admin/rooms/{id}/time-slots/overview
|                          → giống rooms/{id}/time-slots nhưng KHÔNG có price/final_price/
|                            has_promotion/is_increase/promotions (xem RoomController::
|                            timeSlotsOverview()).
| POST/DELETE /api/admin/rooms/{id}/block
|                          → khoá/mở khoá lịch phòng. styles=1: body room_time_slot_ids[]+date_from+
|                            date_to (ghi room_time_slots.settings.blocked_dates). styles=2: body
|                            date_from+date_to (ghi products.room_config.blocked_ranges) — xem
|                            docblock App\Http\Controllers\Api\Admin\RoomBlockController.
| PATCH /api/admin/rooms/{id}/booking-settings
|                          → cấu hình đặt phòng: full_booking_discount/bulk_discount_rules/
|                            room_config (như discount-settings nhưng cho 1 phòng) + deposit_1_night/
|                            deposit_multi_night/deposit_min_nights/default_checkin/default_checkout
|                            (xem docblock ProductController::updateBookingSettings()).
| GET/POST/DELETE /api/admin/rooms/{id}/promotions
|                          → gán/gỡ ưu đãi (Promotion) cho 1 khung giờ của phòng (bảng pivot
|                            promotion_room_time_slot) — xem RoomPromotionController.
| GET/POST /api/admin/rooms/{id}/pricing
|                          → gộp XEM + SỬA giá khung giờ (room_time_slots) VÀ điều kiện giảm giá
|                            (full_booking_discount/bulk_discount_rules/room_config/deposit_1_night/
|                            deposit_multi_night/deposit_min_nights/default_checkin/default_checkout)
|                            cho 1 phòng — xem RoomPricingController.
| DELETE /api/admin/rooms/{id}/pricing/bulk-discount-rules → xoá bulk_discount_rules (styles=1)
| DELETE /api/admin/rooms/{id}/pricing/time-slots/{timeslotId} → gỡ hẳn 1 khung giờ khỏi phòng
|                          (xoá bản ghi room_time_slots, styles=1)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('branches', [AdminBranchController::class, 'index'])->name('branches.index');
    Route::get('rooms',    [AdminRoomController::class, 'index'])->name('rooms.index');
    Route::get('rooms/{id}/pricing',   [AdminRoomPricingController::class, 'show'])->name('rooms.pricing.show');
    Route::post('rooms/{id}/pricing',  [AdminRoomPricingController::class, 'update'])->name('rooms.pricing.update');
    Route::delete('rooms/{id}/pricing/bulk-discount-rules', [AdminRoomPricingController::class, 'deleteBulkDiscountRules'])->name('rooms.pricing.bulk-discount-rules.destroy');
    Route::delete('rooms/{id}/pricing/time-slots/{timeslotId}', [AdminRoomPricingController::class, 'deleteTimeSlot'])->name('rooms.pricing.time-slots.destroy');
    Route::get('rooms/{id}/time-slots', [AdminRoomController::class, 'timeSlots'])->name('rooms.time-slots');
    Route::get('rooms/{id}/time-slots/overview', [AdminRoomController::class, 'timeSlotsOverview'])->name('rooms.time-slots.overview');
    Route::post('rooms/{id}/time-slot-hold',   [AdminTimeSlotHoldController::class, 'hold'])->name('rooms.time-slot-hold');
    Route::delete('rooms/{id}/time-slot-hold', [AdminTimeSlotHoldController::class, 'release'])->name('rooms.time-slot-hold.release');
    Route::get('rooms/{id}/dates', [AdminRoomController::class, 'dates'])->name('rooms.dates');
    Route::post('rooms/{id}/hold',   [AdminDailyRoomHoldController::class, 'hold'])->name('rooms.daily-hold');
    Route::delete('rooms/{id}/hold', [AdminDailyRoomHoldController::class, 'release'])->name('rooms.daily-hold.release');
    Route::patch('rooms/{id}/status', [AdminProductController::class, 'updateStatus'])->name('rooms.status');
    Route::post('rooms/{id}/block',   [AdminRoomBlockController::class, 'block'])->name('rooms.block');
    Route::delete('rooms/{id}/block', [AdminRoomBlockController::class, 'unblock'])->name('rooms.block.release');
    Route::patch('rooms/{id}/booking-settings', [AdminProductController::class, 'updateBookingSettings'])->name('rooms.booking-settings');
    Route::get('rooms/{id}/promotions',    [AdminRoomPromotionController::class, 'index'])->name('rooms.promotions.index');
    Route::post('rooms/{id}/promotions',   [AdminRoomPromotionController::class, 'store'])->name('rooms.promotions.store');
    Route::delete('rooms/{id}/promotions/{promotionId}', [AdminRoomPromotionController::class, 'destroy'])->name('rooms.promotions.destroy');
});

/*
|--------------------------------------------------------------------------
| Categories — Quản lý CHI NHÁNH (parent_id=null) và KHU VỰC (parent_id=id chi nhánh),
| category_type luôn 'product' — xem docblock App\Http\Controllers\Api\Admin\CategoryController.
| GET    /api/admin/categories          → ?parent_id= (bỏ trống=chi nhánh gốc, 1 id=khu vực con,
|                                          'all'=phẳng hết) + ?search=
| GET    /api/admin/categories/tree     → toàn bộ chi nhánh + khu vực con, dạng CÂY lồng nhau
|                                          (children[]) — cùng phạm vi hiển thị với field
|                                          "categories" ở POST /api/admin/login. Dùng cho FE hiển
|                                          thị dropdown/tree chọn "Thuộc chi nhánh".
|                                          ?categories={slug,...} → chỉ trả (những) chi nhánh đó
|                                          kèm khu vực con của nó, giống ?categories= của các API
|                                          admin khác (dashboard/kpi-stats, rankings, rooms).
| GET    /api/admin/categories/{id}     → chi tiết 1 chi nhánh/khu vực
| POST   /api/admin/categories          → tạo (multipart/form-data nếu có ảnh)
| PUT|POST /api/admin/categories/{id}   → sửa (POST khi cần gửi kèm ảnh, PHP không tự parse
|                                          multipart cho method PUT thật)
| DELETE /api/admin/categories/{id}     → xoá — CHẶN nếu còn khu vực con / đang gán quyền nhân
|                                          viên / còn phòng hoặc đơn đặt phòng gắn vào
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/categories')->name('api.admin.categories.')->group(function () {
    Route::get('/',        [AdminCategoryController::class, 'index'])->name('index');
    Route::get('/tree',    [AdminCategoryController::class, 'tree'])->name('tree');
    Route::get('/{id}',    [AdminCategoryController::class, 'show'])->name('show')->whereNumber('id');
    Route::post('/',       [AdminCategoryController::class, 'store'])->name('store');
    Route::put('/{id}',    [AdminCategoryController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}',   [AdminCategoryController::class, 'update'])->name('update.multipart')->whereNumber('id');
    Route::delete('/{id}', [AdminCategoryController::class, 'destroy'])->name('destroy')->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Orders — Danh sách đơn đặt phòng theo đối tác của tài khoản đang đăng nhập
| GET /api/admin/orders      → lọc tự động theo users.partner_id (super_admin xem hết); hỗ trợ
|                               thêm ?filter[branch_id|categories|room_id|status|payment_method|
|                               search|from|to|checkin_date|checkout_date]=... (hoặc param phẳng
|                               tương đương, vd ?branch_id=.../?categories=... — xem docblock
|                               OrderController::index()) + per_page
| POST /api/admin/orders                    → tạo đơn hộ khách (vãng lai hoặc đã là thành viên)
| POST /api/admin/orders/preview            → tính giá đơn MỚI, KHÔNG tạo/giữ chỗ (dry-run) — body
|                                              giống hệt POST /api/admin/orders, trả về đúng khối
|                                              summary/guest_surcharge/system_discount/deposit như
|                                              response tạo đơn, không có 'order'. Gọi lại liên tục
|                                              không tác dụng phụ — xem docblock BookingController::preview().
| GET /api/admin/orders/{order_code}        → chi tiết đầy đủ 1 đơn (items, dịch vụ, CCCD khách
|                                              chính + khách đi cùng ĐÃ LƯU, cọc, mốc thời gian
|                                              thanh toán/nhận-trả phòng, người tạo đơn) — xem
|                                              ngay không cần gửi kèm request cập nhật.
| PUT|POST /api/admin/orders/{order_code}   → sửa đơn (ghi chú, trạng thái, CCCD, khung giờ, phụ thu/
|                                              tổng tiền tay, ĐỔI PHÒNG) — tra theo order_code, KHÔNG
|                                              phải id nội bộ. Nhận CẢ PUT lẫn POST — dùng POST khi
|                                              cần gửi multipart/form-data kèm file (PHP không tự
|                                              parse form-data cho method PUT thật, kể cả không có file).
|                                              Đổi phòng: gửi kèm 'room_id' (id phòng đích) cùng với
|                                              'type' + slots[]/checkin_date+checkout_date theo cấu
|                                              hình PHÒNG MỚI — xem docblock OrderController::update().
| POST /api/admin/orders/{order_code}/preview
|                                          → tính lại giá khi đổi phòng/khung giờ/khách/dịch vụ của
|                                            đơn NÀY, KHÔNG ghi DB (dry-run) — body giống phần "Đổi
|                                            khung giờ/ngày" ở trên, trả cùng khối summary/
|                                            guest_surcharge/system_discount/deposit như response
|                                            tạo/sửa đơn — xem docblock OrderController::preview().
| DELETE /api/admin/orders/{order_code}/guests/{guest_index}
|                                          → xoá CCCD 1 khách đi cùng (guest_index từ 2) — dùng khi
|                                            giảm số khách, không tự giảm guest_count của đơn.
| POST /api/admin/orders/{order_code}/unlock
|                                          → mở cổng TTLock hộ khách — CHỈ áp dụng cho đơn thuộc
|                                            chi nhánh đã đăng ký tài khoản TTLock đang hoạt động.
|                                            Admin được BỎ QUA cửa sổ giờ nhận/trả phòng (khách tự mở
|                                            qua app thì vẫn bị chặn ngoài giờ) — vẫn cập nhật
|                                            checked_in_at/checked_out_at + order_status như bình thường.
| POST /api/admin/orders/{order_code}/open-gate
|                                          → TOGGLE cờ unlock_anytime của đơn (BẬT/TẮT — gọi lại lần
|                                            nữa để khoá lại). Khi bật, CHÍNH KHÁCH HÀNG của đơn được
|                                            tự mở cổng qua app (POST /api/orders/{order_code}/unlock
|                                            hoặc /api/guest/...) vào bất kỳ lúc nào, không giới hạn
|                                            khung giờ nhận/trả phòng. Cùng logic với action
|                                            toggle_unlock_anytime ở bảng đơn hàng (Filament).
| POST /api/admin/orders/{order_code}/remaining-payment
|                                          → QR (hoặc xác nhận tiền mặt) cho phần còn lại của đơn
|                                            đặt cọc — áp dụng cho cả status="deposit" LẪN "pending"
|                                            (PayOS mới tạo luôn ở pending, không cần bước xác nhận
|                                            trung gian nào khác) — không đặt thêm gì, chỉ tất toán cọc.
| POST /api/admin/orders/{order_code}/extra
|                                          → đặt thêm dịch vụ/khách/khung giờ hộ khách trên đơn đã
|                                            paid/deposit (áp dụng cả slot lẫn daily) — trả kèm QR
|                                            cho phần còn lại (đơn deposit) hoặc phần vừa đặt thêm
|                                            (đơn paid) ngay trong response.
| POST /api/admin/orders/{order_code}/extra-charge-qr
|                                          → tạo lại QR cho khoản phát sinh đang chờ thanh toán (đơn
|                                            paid, QR cũ từ .../extra đã hết hạn).
| POST /api/admin/orders/{order_code}/retry-payment
|                                          → tạo lại QR PayOS cho đơn "failed"/"cancelled_payment",
|                                            đơn tự chuyển về "pending" — trả kèm qr_code + expired_at.
| DELETE /api/admin/orders/{order_code}
|                                          → xoá HẲN 1 đơn (hard delete, không thể khôi phục) — dọn
|                                            luôn order_items/order_services/order_guest_cccds + file
|                                            CCCD trong storage. Không giới hạn theo status đơn.
| DELETE /api/admin/orders
|                                          → xoá NHIỀU đơn cùng lúc, body {"order_codes": [...]}.
|                                            Đơn không tồn tại/ngoài phạm vi đối tác bị bỏ qua, trả về
|                                            trong 'not_found' thay vì lỗi cả request.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/orders')->name('api.admin.orders.')->group(function () {
    Route::get('/',                       [OrderController::class, 'index'])->name('index');
    Route::post('/',                      [AdminBookingController::class, 'store'])->name('store');
    // /preview đứng TRƯỚC /{order_code} — route param sẽ nuốt luôn chuỗi 'preview' làm order_code
    // nếu định nghĩa sau, do Laravel khớp theo thứ tự khai báo (xem docblock preview() ở BookingController).
    Route::post('/preview',               [AdminBookingController::class, 'preview'])->name('preview');
    Route::get('/{order_code}',            [OrderController::class, 'show'])->name('show');
    Route::get('/{order_code}/cccds',      [OrderController::class, 'cccds'])->name('cccds');
    Route::post('/{order_code}/preview',   [OrderController::class, 'preview'])->name('order-preview');
    Route::match(['put', 'post'], '/{order_code}', [OrderController::class, 'update'])->name('update');
    Route::delete('/', [OrderController::class, 'destroyBatch'])->name('destroy-batch');
    Route::delete('/{order_code}', [OrderController::class, 'destroy'])->name('destroy');
    Route::delete('/{order_code}/guests/{guest_index}', [OrderController::class, 'destroyGuestCccd'])
        ->name('guests.destroy')
        ->whereNumber('guest_index');
    Route::post('/{order_code}/unlock',            [AdminUnlockController::class, 'unlock'])->name('unlock');
    Route::post('/{order_code}/open-gate',         [AdminUnlockController::class, 'openGate'])->name('open-gate');
    Route::post('/{order_code}/retry-payment',     [OrderPaymentController::class, 'retryPayment'])->name('retry-payment');
    Route::post('/{order_code}/remaining-payment', [OrderPaymentController::class, 'remainingPayment'])->name('remaining-payment');
    Route::post('/{order_code}/extra',             [OrderPaymentController::class, 'addExtra'])->name('extra');
    Route::post('/{order_code}/extra-charge-qr',   [OrderPaymentController::class, 'extraChargeQr'])->name('extra-charge-qr');
});

/*
|--------------------------------------------------------------------------
| Employee — Hồ sơ nhân viên (module chính, khớp form "Thêm mới nhân viên")
| GET    /api/admin/employees      → Danh sách (?search=&department_id=&position_id=&branch_id=&status=&page=)
| GET    /api/admin/employees/{id} → Chi tiết đầy đủ (kèm chi nhánh làm việc, phụ cấp, giảm trừ...)
| POST   /api/admin/employees      → Tạo mới (multipart/form-data — hỗ trợ upload avatar)
| POST   /api/admin/employees/{id} → Cập nhật (multipart/form-data — dùng POST thay PUT vì PHP không
|                                     parse được multipart trên method PUT/PATCH)
| DELETE /api/admin/employees/{id} → Xoá
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/employees')->name('api.admin.employees.')->group(function () {
    Route::get('/',        [EmployeeController::class, 'index'])->name('index');
    Route::get('/{id}',    [EmployeeController::class, 'show'])->name('show')->whereNumber('id');
    Route::post('/',       [EmployeeController::class, 'store'])->name('store');
    Route::post('/{id}',   [EmployeeController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [EmployeeController::class, 'destroy'])->name('destroy')->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Customers — Hồ sơ khách hàng (App\Models\Customer). Khách hàng KHÔNG thuộc riêng đối tác nào
| (xem App\Models\Concerns\BelongsToPartner) nên không lọc theo partner_id như employees/rooms.
| Lúc TẠO, tự động gán categories = toàn bộ chi nhánh gốc user đang tạo quản lý (xem
| CustomerController::store()); user thường CHỈ xem/sửa được khách hàng thuộc categories mình
| quản lý HOẶC khách hàng cũ chưa có categories nào (xem visibleCustomersQuery()), super_admin
| xem hết.
| GET    /api/admin/customers      → Danh sách (?search=&fullname=&phone=&branch_id=&status=&
|                                     membership_tier_id=&page=)
| GET    /api/admin/customers/{id} → Chi tiết (kèm tỉnh/thành, hạng thành viên, khách đi cùng đã lưu)
| POST   /api/admin/customers      → Tạo mới (multipart/form-data — hỗ trợ upload CCCD 2 mặt, tự
|                                     quét QR lưu vào cccd_data nếu đọc được, giống EditCustomer ở
|                                     Filament — xem CustomerController::handleCccd())
| POST   /api/admin/customers/{id} → Cập nhật (multipart/form-data — dùng POST thay PUT vì PHP
|                                     không parse được multipart cho method PUT/PATCH thật)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/customers')->name('api.admin.customers.')->group(function () {
    Route::get('/',      [AdminCustomerController::class, 'index'])->name('index');
    Route::get('/{id}',  [AdminCustomerController::class, 'show'])->name('show');
    Route::post('/',     [AdminCustomerController::class, 'store'])->name('store');
    Route::post('/{id}', [AdminCustomerController::class, 'update'])->name('update');
});

/*
|--------------------------------------------------------------------------
| Customer Companions — CCCD "khách đi cùng" đã LƯU SẴN vào hồ sơ 1 khách hàng
| (customer_companions), tái sử dụng được cho nhiều lần đặt phòng qua đêm sau này — khác với CCCD
| khách đi cùng gắn riêng theo từng đơn (guests[] ở POST /api/admin/orders).
|
| Luồng FE khi admin tạo đơn và CHỌN khách hàng có sẵn: dựa vào guest_count, hiển thị đúng
| (guest_count - 1) ô khách đi cùng — mỗi ô cho CHỌN 1 companion có sẵn ở GET .../companions, hoặc
| THÊM MỚI (quét CCCD ngay trong lúc tạo) qua POST .../companions — gửi ĐƯỢC NHIỀU companion cùng
| lúc trong 1 request (ví dụ guest_count=3 → gửi 3 companion 1 lần thay vì gọi POST 3 lần).
|
| GET    /api/admin/customers/{customer_id}/companions      → Danh sách companion đã lưu
| POST   /api/admin/customers/{customer_id}/companions      → Thêm mới HÀNG LOẠT (multipart),
|                                                              mỗi companion CHỌN 1 trong 2 chế độ:
|                                                              - ẢNH: companions[{i}][cccd_front|
|                                                                cccd_back] — quét QR/OCR, full_name
|                                                                lấy tự động từ kết quả quét
|                                                              - NHẬP TAY: companions[{i}][full_name|
|                                                                cccd|dob|gender|address] — không có
|                                                                ảnh, full_name + cccd bắt buộc
|                                                              — transaction, 1 lỗi rollback cả batch
| POST   /api/admin/customers/{customer_id}/companions/{id} → Quét lại CCCD (không nhận full_name,
|                                                              đồng bộ với POST tạo mới) — chỉ có tác
|                                                              dụng khi gửi ĐỦ CẢ front+back
| DELETE /api/admin/customers/{customer_id}/companions/{id} → Xoá
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/customers/{customer_id}/companions')->name('api.admin.customers.companions.')->group(function () {
    Route::get('/',       [AdminCustomerCompanionController::class, 'index'])->name('index');
    Route::post('/',      [AdminCustomerCompanionController::class, 'store'])->name('store');
    Route::post('/{id}',  [AdminCustomerCompanionController::class, 'update'])->name('update')->whereNumber('id');
    Route::delete('/{id}', [AdminCustomerCompanionController::class, 'destroy'])->name('destroy')->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Services — Danh sách dịch vụ bổ sung (additional_services) theo đối tác, dùng để admin lấy đúng
| service_id khi tạo/sửa đơn (POST /api/admin/orders, services[].service_id) — xem
| BuildsRoomBooking::buildServices() (dùng $room->additionalServices(), KHÔNG phải room_services).
| GET /api/admin/services → ?is_active=&search=
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/services')->name('api.admin.services.')->group(function () {
    Route::get('/', [AdminServiceController::class, 'index'])->name('index');
});

/*
|--------------------------------------------------------------------------
| Tags — Danh sách TIỆN ÍCH phòng (bảng `tags`, field "tags" trong ProductForm — label hiển thị
| "Tiện ích", KHÔNG phải RoomAmenity). Dùng để admin chọn tag_id khi tạo/sửa phòng
| (POST/PUT /api/admin/products, tags[]).
| GET /api/admin/tags → ?search=
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/tags')->name('api.admin.tags.')->group(function () {
    Route::get('/', [AdminTagController::class, 'index'])->name('index');
});

/*
|--------------------------------------------------------------------------
| Time Slots — Danh sách khung giờ dùng chung (bảng `time_slots`), đã chia sẵn 4 nhóm
| Ngày/Chiều/Đêm/Qua đêm — xem TimeSlotController. Dùng làm dữ liệu nền để gán khung giờ cho
| từng phòng (POST /api/admin/products/{id}/time-slots).
| GET /api/admin/time-slots → ?type=&include_date_type=&key=&label=
| POST /api/admin/time-slots → tạo khung giờ mới (start_time/end_time/over_night/label/type)
| PUT|PATCH /api/admin/time-slots/{id} → sửa — LƯU Ý dùng chung cho mọi phòng đang gán khung giờ này
| DELETE /api/admin/time-slots/{id} → xoá — chặn nếu còn phòng nào đang gán khung giờ này
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/time-slots')->name('api.admin.time-slots.')->group(function () {
    Route::get('/', [AdminTimeSlotController::class, 'index'])->name('index');
    Route::post('/', [AdminTimeSlotController::class, 'store'])->name('store');
    Route::put('/{id}', [AdminTimeSlotController::class, 'update'])->name('update');
    Route::patch('/{id}', [AdminTimeSlotController::class, 'update'])->name('update.patch');
    Route::delete('/{id}', [AdminTimeSlotController::class, 'destroy'])->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Promotions — Quản lý ƯU ĐÃI (bảng `promotions`, module Promotion), CRUD đầy đủ — xem docblock
| App\Http\Controllers\Api\Admin\PromotionController. Khác với GET/POST/DELETE
| /api/admin/rooms/{id}/promotions (RoomPromotionController — chỉ gán/gỡ ưu đãi CÓ SẴN cho khung
| giờ của 1 phòng, không tạo/sửa/xoá bản ghi Promotion).
| GET    /api/admin/promotions      → ?search=&type=&is_active=&category_id=&categories[]=&per_page=
| GET    /api/admin/promotions/{id} → chi tiết
| POST   /api/admin/promotions      → tạo (categories[] bắt buộc, kèm ảnh thì multipart/form-data)
| PUT|POST /api/admin/promotions/{id} → sửa (POST khi cần gửi kèm ảnh)
| DELETE /api/admin/promotions/{id} → xoá — chặn nếu còn gán cho khung giờ phòng nào
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/promotions')->name('api.admin.promotions.')->group(function () {
    Route::get('/', [AdminPromotionController::class, 'index'])->name('index');
    Route::get('/{id}', [AdminPromotionController::class, 'show'])->name('show');
    Route::post('/', [AdminPromotionController::class, 'store'])->name('store');
    Route::put('/{id}', [AdminPromotionController::class, 'update'])->name('update');
    Route::post('/{id}', [AdminPromotionController::class, 'update'])->name('update.post');
    Route::delete('/{id}', [AdminPromotionController::class, 'destroy'])->name('destroy');
});

/*
|--------------------------------------------------------------------------
| Room Types — Danh mục PHÒNG dùng chung cho mọi đối tác (bảng `room_types`, vd "Theo giờ",
| "Theo ngày"...), KHÔNG phải chi nhánh/khu vực (đó là `categories`, xem admin/branches). Dùng làm
| dữ liệu nền cho dropdown room_type_id khi tạo/sửa phòng và lọc GET /api/admin/products.
| GET /api/admin/room-types → ?is_active= (mặc định trả tất cả, kể cả đang tắt)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/room-types')->name('api.admin.room-types.')->group(function () {
    Route::get('/', [AdminRoomTypeController::class, 'index'])->name('index');
});

/*
|--------------------------------------------------------------------------
| Products — Quản lý PHÒNG (bảng `products`) — xem docblock App\Http\Controllers\Api\Admin\ProductController.
| GET    /api/admin/products                     → ?category_id=&search=&status=&per_page= (ảnh
|                                                    chính, tên, chi nhánh, trạng thái)
| GET    /api/admin/products/{id}                → chi tiết đầy đủ
| POST   /api/admin/products                     → tạo (multipart/form-data — ảnh chính bắt buộc)
| PUT|POST /api/admin/products/{id}               → sửa (POST khi cần gửi kèm ảnh)
| DELETE /api/admin/products/{id}                → xoá — chặn nếu còn đơn đặt phòng gắn vào
| POST   /api/admin/products/discount-settings   → thiết lập full_booking_discount/
|                                                    bulk_discount_rules/room_config cho 1 hoặc
|                                                    nhiều phòng cùng lúc (body: room_ids[])
| GET    /api/admin/products/{id}/time-slots     → danh sách khung giờ đã gán cho phòng
|                                                    (room_time_slots: room_id, timeslot_id, price,
|                                                    over_night)
| POST   /api/admin/products/{id}/time-slots     → thêm/cập nhật khung giờ cho phòng (body: slots[])
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/products')->name('api.admin.products.')->group(function () {
    Route::get('/',                  [AdminProductController::class, 'index'])->name('index');
    // Đăng ký TRƯỚC route '/{id}' — 'discount-settings' sẽ bị route '/{id}' (không giới hạn định
    // dạng, khớp mọi chuỗi vì khoá chính Product là ULID) nuốt mất nếu đứng sau.
    Route::post('/discount-settings', [AdminProductController::class, 'discountSettings'])->name('discount-settings');
    Route::post('/',                 [AdminProductController::class, 'store'])->name('store');
    Route::get('/{id}',              [AdminProductController::class, 'show'])->name('show');
    Route::put('/{id}',              [AdminProductController::class, 'update'])->name('update');
    Route::post('/{id}',             [AdminProductController::class, 'update'])->name('update.multipart');
    Route::delete('/{id}',           [AdminProductController::class, 'destroy'])->name('destroy');

    Route::get('/{id}/time-slots',   [AdminRoomTimeSlotController::class, 'index'])->name('time-slots.index');
    Route::post('/{id}/time-slots',  [AdminRoomTimeSlotController::class, 'store'])->name('time-slots.store');
});

/*
|--------------------------------------------------------------------------
| CCCD — Quét độc lập 1 cặp ảnh CCCD (không gắn vào đơn/khách hàng nào), dùng cho FE hiển thị
| preview thông tin từng khách TRƯỚC khi submit đơn — đặc biệt hữu ích khi khung giờ qua đêm có
| guest_count > 1 (gọi lặp lại đúng guest_count lần, mỗi lần kèm guest_index để map đúng ô đang
| nhập) — xem docblock CccdController::scan(). Quét lỗi vẫn trả 200 (scanned=false, data=null) để
| admin/lễ tân tự nhập tay, không chặn luồng.
| POST /api/admin/cccd/scan → body multipart {front, back, guest_index?}
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/cccd')->name('api.admin.cccd.')->group(function () {
    Route::post('/scan', [AdminCccdController::class, 'scan'])->name('scan');
});
