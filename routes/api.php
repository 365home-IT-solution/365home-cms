<?php

use App\Http\Controllers\Api\Auth\ZaloOtpController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\LockRecordCallbackController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomTypeController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;
use Modules\AppPage\App\Http\Controllers\AppPageController;

/*
|--------------------------------------------------------------------------
| TTLock Callback
| Callback URL cần đăng ký trong TTLock Management Center > Application
| URL: https://365home.vn/api/lock/callback
|--------------------------------------------------------------------------
*/
Route::post('lock/callback', [LockRecordCallbackController::class, 'handle'])
    ->name('lock.callback');

/*
|--------------------------------------------------------------------------
| Zalo OTP Auth
| POST /api/auth/send-otp   → Gửi OTP về Zalo của khách
| POST /api/auth/verify-otp → Xác nhận OTP, trả Sanctum token
| POST /api/auth/logout     → Đăng xuất (xoá token hiện tại)
| GET  /api/auth/me         → Thông tin user đang đăng nhập
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Room Types
| GET /api/room-types → Danh sách danh mục phòng (dùng cho select/dropdown)
|--------------------------------------------------------------------------
*/
Route::get('room-types', [RoomTypeController::class, 'index'])->name('api.room-types.index');

/*
|--------------------------------------------------------------------------
| V1 — Home
| GET /api/v1/home           → Full sections (banner + room_list)
| GET /api/v1/home?tab={id}  → Banner + room_list lọc theo tab
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('home', HomeController::class)->name('home');
});

/*
|--------------------------------------------------------------------------
| App Pages
| GET /api/pages/{slug} → Nội dung trang theo slug (home, payment, ...)
|--------------------------------------------------------------------------
*/
Route::get('pages/{slug}', [AppPageController::class, 'show'])->name('api.pages.show');

/*
|--------------------------------------------------------------------------
| Rooms
| GET /api/rooms/{slug} → Chi tiết phòng (amenities, services, specials,
|                          prices, promotions, coupons)
|--------------------------------------------------------------------------
*/
Route::get('rooms/{slug}', [RoomController::class, 'show'])->name('api.rooms.show');
Route::get('slots', [RoomController::class, 'slots'])->name('api.slots');

/*
|--------------------------------------------------------------------------
| Dev / Test bypass — chỉ hoạt động khi OTP_BYPASS_ENABLED=true
| GET /api/auth/dev-otp/{phone} → trả mã OTP hiện tại từ cache
|--------------------------------------------------------------------------
*/
if (config('app.otp_bypass_enabled', false)) {
    Route::get('auth/dev-otp/{phone}', function (string $phone) {
        $service = app(\App\Services\ZaloOtpService::class);
        $normalized = $service->normalizePhone($phone);
        $otp = \Illuminate\Support\Facades\Cache::get('zalo_otp:' . $normalized);
        if (! $otp) {
            return response()->json(['message' => 'Không có OTP nào đang chờ cho số này.'], 404);
        }
        return response()->json(['phone' => $normalized, 'otp' => $otp]);
    })->name('api.auth.dev-otp');
}

Route::prefix('auth')->name('api.auth.')->group(function () {
    // Đăng nhập
    Route::post('send-otp',   [ZaloOtpController::class, 'sendOtp'])->name('send-otp')->middleware('throttle:otp-send');
    Route::post('verify-otp', [ZaloOtpController::class, 'verifyOtp'])->name('verify-otp')->middleware('throttle:otp-verify');

    // Đăng ký
    Route::post('register', [ZaloOtpController::class, 'register'])->name('register')->middleware('throttle:register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout',  [ZaloOtpController::class, 'logout'])->name('logout');
        Route::get('me',       [ZaloOtpController::class, 'me'])->name('me');
        Route::post('me',      [ZaloOtpController::class, 'update'])->name('me.update');
    });
});

/*
|--------------------------------------------------------------------------
| Orders / Booking
| POST /api/orders → Tạo đơn đặt phòng (slot hoặc monthly)
|                    - Nếu có token Sanctum: lấy buyer từ customer
|                    - Nếu không có token:  bắt buộc buyer_name + buyer_phone
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->post('orders', [BookingController::class, 'store'])->name('api.orders.store');

/*
|--------------------------------------------------------------------------
| Wishlist
| GET  /api/wishlist                    → Danh sách phòng đã lưu
| POST /api/wishlist/{product}/toggle   → Thêm / bỏ khỏi wishlist
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('wishlist')->name('api.wishlist.')->group(function () {
    Route::get('/',                      [WishlistController::class, 'index'])->name('index');
    Route::post('{slug}/toggle',          [WishlistController::class, 'toggle'])->name('toggle');
});

