<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Api\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Api\Admin\AllowanceTypeController;
use App\Http\Controllers\Api\Admin\DeductionTypeController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\EmployeeController;
use App\Http\Controllers\Api\Admin\OrderController;
use App\Http\Controllers\Api\Admin\PositionController;
use App\Http\Controllers\Api\Admin\SalaryTemplateController;
use App\Http\Controllers\Api\Admin\SalaryTypeController;
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
| Orders — Danh sách đơn đặt phòng theo đối tác của tài khoản đang đăng nhập
| GET /api/admin/orders      → lọc tự động theo users.partner_id (super_admin xem hết);
|                               hỗ trợ thêm branch_id, status, payment_method, search, from, to, per_page
| POST /api/admin/orders                    → tạo đơn hộ khách (vãng lai hoặc đã là thành viên)
| PUT|POST /api/admin/orders/{order_code}   → sửa đơn (ghi chú, trạng thái, CCCD, khung giờ, phụ thu/
|                                              tổng tiền tay) — tra theo order_code, KHÔNG phải id
|                                              nội bộ. Nhận CẢ PUT lẫn POST — dùng POST khi cần gửi
|                                              multipart/form-data kèm file (PHP không tự parse
|                                              form-data cho method PUT thật, kể cả không có file).
| DELETE /api/admin/orders/{order_code}/guests/{guest_index}
|                                          → xoá CCCD 1 khách đi cùng (guest_index từ 2) — dùng khi
|                                            giảm số khách, không tự giảm guest_count của đơn.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'admin.api'])->prefix('admin/orders')->name('api.admin.orders.')->group(function () {
    Route::get('/',                       [OrderController::class, 'index'])->name('index');
    Route::post('/',                      [AdminBookingController::class, 'store'])->name('store');
    Route::match(['put', 'post'], '/{order_code}', [OrderController::class, 'update'])->name('update');
    Route::delete('/{order_code}/guests/{guest_index}', [OrderController::class, 'destroyGuestCccd'])
        ->name('guests.destroy')
        ->whereNumber('guest_index');
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
