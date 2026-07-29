<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\BranchController as AdminBranchController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Api\Admin\AllowanceTypeController;
use App\Http\Controllers\Api\Admin\DailyRoomHoldController as AdminDailyRoomHoldController;
use App\Http\Controllers\Api\Admin\DeductionTypeController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\OrderPaymentController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\RoomController as AdminRoomController;
use App\Http\Controllers\Api\Admin\SalaryTemplateController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
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
|                            xem thêm 10 ngày tiếp theo (xem docblock RoomController::timeSlots()).
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
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin')->name('api.admin.')->group(function () {
    Route::get('branches', [AdminBranchController::class, 'index'])->name('branches.index');
    Route::get('rooms',    [AdminRoomController::class, 'index'])->name('rooms.index');
    Route::get('rooms/{id}/time-slots', [AdminRoomController::class, 'timeSlots'])->name('rooms.time-slots');
    Route::post('rooms/{id}/time-slot-hold',   [AdminTimeSlotHoldController::class, 'hold'])->name('rooms.time-slot-hold');
    Route::delete('rooms/{id}/time-slot-hold', [AdminTimeSlotHoldController::class, 'release'])->name('rooms.time-slot-hold.release');
    Route::get('rooms/{id}/dates', [AdminRoomController::class, 'dates'])->name('rooms.dates');
    Route::post('rooms/{id}/hold',   [AdminDailyRoomHoldController::class, 'hold'])->name('rooms.daily-hold');
    Route::delete('rooms/{id}/hold', [AdminDailyRoomHoldController::class, 'release'])->name('rooms.daily-hold.release');
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
|                               thêm ?filter[branch_id|room_id|status|payment_method|search|from|
|                               to|checkin_date|checkout_date]=... (hoặc param phẳng tương đương,
|                               vd ?branch_id=... — xem docblock OrderController::index()) + per_page
| POST /api/admin/orders                    → tạo đơn hộ khách (vãng lai hoặc đã là thành viên)
| PUT|POST /api/admin/orders/{order_code}   → sửa đơn (ghi chú, trạng thái, CCCD, khung giờ, phụ thu/
|                                              tổng tiền tay) — tra theo order_code, KHÔNG phải id
|                                              nội bộ. Nhận CẢ PUT lẫn POST — dùng POST khi cần gửi
|                                              multipart/form-data kèm file (PHP không tự parse
|                                              form-data cho method PUT thật, kể cả không có file).
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
